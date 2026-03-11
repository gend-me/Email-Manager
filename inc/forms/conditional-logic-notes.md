# Conditional Logic - Implementation Notes

## Data Structure

Each question can have conditional logic defining when it should be shown:

```javascript
{
  id: 2,
  text: "What's your favorite feature?",
  type: "select",
  options: [...],
  conditional: {
    enabled: true,
    show_if: "all", // or "any"
    rules: [
      {
        question: 0,  // Question ID to check
        operator: "equals",  // equals, not_equals, contains, not_contains
        value: "yes"
      }
    ]
  }
}
```

## Supported Operators
- `equals`: exact match
- `not_equals`: not equal to
- `contains`: answer contains value (for text inputs)
- `not_contains`: answer does not contain value

## Frontend Logic
The frontend JS will evaluate rules before showing each question:
1. Check if question has conditional logic enabled
2. Evaluate all rules based on previous answers
3. If rules pass, show question; otherwise skip to next

## Backend UI
- Toggle to enable/disable conditional logic per question
- Rule builder with:
  - Dropdown to select which previous question
  - Operator dropdown
  - Value input
  - Add/Remove rule buttons
- Show/hide logic selector (all/any rules must match)
