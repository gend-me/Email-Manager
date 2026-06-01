# Member Inbox — Per-Container Provisioning Runbook

> One-time steps to enable inbound member mail for a new customer container.
> Automation script (slice 2d.1) will encode these; for now they're manual.

## Inputs you need before starting

| Variable | Example | Where it comes from |
|----------|---------|---------------------|
| `NS` | `wp-676babb3-014f-4b40-8991-459d5782557a` | The customer container's K8s namespace |
| `DOMAIN` | `mail.acme.com` | The recipient domain to accept mail for |
| `INSTALL_ID` | `676babb3-014f-4b40-8991-459d5782557a` | The customer's install ID (NS minus the `wp-` prefix) |
| `MTA_IP_NAME` | `mta-acme` | Globally-unique-in-project name for the reserved IP |

## 1. Reserve a static IP for the MTA

```bash
gcloud compute addresses create "$MTA_IP_NAME" \
  --region=us-central1 --network-tier=PREMIUM --project=gend-me

MTA_IP=$(gcloud compute addresses describe "$MTA_IP_NAME" \
  --region=us-central1 --project=gend-me --format='value(address)')
echo "$MTA_IP"
```

## 2. Workload Identity binding for the MTA (GCS write access)

The pod's k8s SA `email-mta` impersonates `email-mta-sa@gend-me` so it can
write attachments to `gs://gend-email-attachments`.

```bash
gcloud iam service-accounts add-iam-policy-binding \
  email-mta-sa@gend-me.iam.gserviceaccount.com \
  --role=roles/iam.workloadIdentityUser \
  --member="serviceAccount:gend-me.svc.id.goog[$NS/email-mta]" \
  --project=gend-me
```

(The WP-side WI for GCS *read* is auto-provisioned by the
`wordpress-app-composition.yaml` composition — no separate step.)

## 3. Pull the per-container HMAC secret from WP

The WP plugin auto-seeds an HMAC secret on activation.

```bash
POD=$(kubectl -n $NS get pod -l app=wordpress -o jsonpath='{.items[0].metadata.name}')
HMAC=$(kubectl -n $NS exec $POD -c wordpress -- \
  wp option get em_inbox_hmac_secret --allow-root --path=/var/www/html)

kubectl -n $NS create secret generic email-mta-secret \
  --from-literal=HMAC_SECRET=$HMAC
```

## 4. Deploy the MTA

Use [`k8s/manifests/email-mta-test.yaml`](../../../../../k8s/manifests/email-mta-test.yaml)
as a template. Replace:
- `wp-676babb3-014f-4b40-8991-459d5782557a` → `$NS`
- `34.45.233.200` → `$MTA_IP`
- `email-mta-test` → `$MTA_IP_NAME` (in the Service's loadBalancerIP comment)
- `mail-test.gend.me` → `$DOMAIN`
- `wp-676babb3-014f-4b40-8991-459d5782557a` (GCS_KEY_PREFIX) → `$NS`

Then:

```bash
kubectl apply -f /tmp/email-mta-$INSTALL_ID.yaml
kubectl -n $NS rollout status deployment/email-mta --timeout=120s
```

## 5. DNS records on the gend-me-public Cloud DNS zone

If `$DOMAIN` is a subdomain of `gend.me`:

```bash
gcloud dns record-sets transaction start --zone=gend-me-public --project=gend-me
gcloud dns record-sets transaction add "$MTA_IP" \
  --name="mta-$INSTALL_ID.gend.me." --ttl=300 --type=A \
  --zone=gend-me-public --project=gend-me
gcloud dns record-sets transaction add "10 mta-$INSTALL_ID.gend.me." \
  --name="$DOMAIN." --ttl=300 --type=MX \
  --zone=gend-me-public --project=gend-me
gcloud dns record-sets transaction execute --zone=gend-me-public --project=gend-me
```

If `$DOMAIN` is a CUSTOMER-OWNED domain (not on gend.me): the customer needs
to add an MX record at their own DNS host pointing at `mta-$INSTALL_ID.gend.me`
(or directly at `$MTA_IP`).

## 6. Register on the hub

```bash
HUB_USER=admin  # whoever is admin on gend.me
HUB_PW=$YOUR_APP_PASSWORD

curl -X POST -u "$HUB_USER:$HUB_PW" \
  -H 'Content-Type: application/json' \
  -d "{
    \"recipient_domain\":    \"$DOMAIN\",
    \"container_namespace\": \"$NS\",
    \"container_url\":       \"https://$DOMAIN\",
    \"mta_static_ip\":       \"$MTA_IP\",
    \"mta_static_ip_name\":  \"$MTA_IP_NAME\",
    \"gcs_key_prefix\":      \"$NS\"
  }" \
  https://gend.me/wp-json/em/v1/inbox/registry
```

(The registry is gated by the `em_inbox_registry_enabled` option on the
hub — run `wp option update em_inbox_registry_enabled 1` once on
the hub site before first use.)

## 7. Smoke-test

```bash
# Internal: send a test message via the LB IP and verify it lands.
kubectl -n $NS exec $POD -c wordpress -- bash -c "
  exec 3<>/dev/tcp/email-mta/25
  read -r line <&3; echo \"banner: \$line\"
"

# External (from anywhere not residential — most ISPs block :25 outbound):
swaks --to test@$DOMAIN --from sender@example.com --server $MTA_IP:25
```

Check the inbox UI at `https://$DOMAIN/wp-admin/admin.php?page=email-manager-inbox`.

## Common gotchas

- **MX record world-visibility blocked on registrar NS cutover.** Until
  `gend.me` NS records at the registrar point at
  `ns-cloud-b{1-4}.googledomains.com`, Cloud DNS additions stay invisible
  to the public internet. Inbox still works for tests that hit the LB IP
  directly; Gmail-to-`$DOMAIN` does NOT until cutover.
- **Apply order matters for the manifest.** The K8s Secret with the HMAC
  must exist BEFORE the Deployment pod starts, or the pod boots, fails to
  read the env, and CrashLoopBackOffs until you restart it.
- **Pod can't bind :25.** The Deployment's `securityContext.sysctls`
  includes `net.ipv4.ip_unprivileged_port_start=25`. Don't strip it.
- **Stale image cache.** The Cloud Build pushes to `email-mta:base`
  (mutable tag). Pods need a `kubectl rollout restart` to repull —
  imagePullPolicy is `Always` but won't re-evaluate without a restart.

## Rotating the HMAC secret

```bash
kubectl -n $NS exec $POD -c wordpress -- wp eval \
  '$new = bin2hex(random_bytes(32)); update_option("em_inbox_hmac_secret", $new, false); echo $new;' \
  --allow-root --path=/var/www/html | tail -1 > /tmp/new-hmac

kubectl -n $NS create secret generic email-mta-secret \
  --from-literal=HMAC_SECRET=$(cat /tmp/new-hmac) \
  --dry-run=client -o yaml | kubectl apply -f -

kubectl -n $NS rollout restart deployment/email-mta
```
