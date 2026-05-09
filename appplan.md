# Homepage Menu App Plan

## Summary

Build the public homepage menu at `/` as a McDelivery-inspired Inertia React page. The page should focus on browsing available food and drinks with compact product cards, local quantity controls, and a detail modal. There is no cart, checkout, or persistence in this phase.

## Design Source

- Create `DESIGN.md` before implementation as the source of truth for the app UI.
- Use a McDelivery-inspired structure without copying McDonald's logos, trademarks, or proprietary product images.
- Use a yellow, red, and white palette with high contrast and compact spacing.
- Keep the first screen focused on the usable menu, not a marketing landing page.

## Public Interface

- Route: `GET /`
- Route name: `home`
- Controller: `MenuController`
- Inertia page: `menu`

## Page Props

- `categories`: fixed labels and counts for Food and Drink.
- `foods`: available menu items with:
  - `id`
  - `name`
  - `slug`
  - `category`
  - `ingredients`
  - `formatted_price`
  - `price_vnd`
  - `image_url`

## Menu Data

- Add a `Food` model for app-facing menu records.
- Store fixed categories as `food` and `drink`, preferably with a PHP enum backed by a string database column.
- Use fields:
  - `name`
  - `slug`
  - `category`
  - `ingredients`
  - `price_vnd`
  - `image_path`
  - `is_available`
  - `sort_order`
- Public menu queries should only show available items and order by category, sort order, then name.

## Visual Assets

- Generate 10 food and drink images.
- Store project assets under `storage/app/public/menu`.
- Seed matching `image_path` values so the public page can render real menu imagery.

## UI Behavior

- Desktop layout:
  - Fixed left category rail.
  - Right product grid.
  - Compact product cards with image, name, ingredients preview, price, and quantity controls.
- Mobile layout:
  - Horizontal category tabs.
  - Single-column or two-column product grid depending on available width.
- Quantity controls:
  - Local React state only.
  - Increment and decrement controls per food item.
  - Prevent quantity from going below zero.
- Detail modal:
  - Opens when a product card or view action is clicked.
  - Shows larger image, name, ingredients, price, category, and quantity stepper.
  - Closes through a close button, backdrop click, and Escape key.

## Tests

- Pest feature test confirms `/` loads successfully.
- Test confirms the response includes the Inertia `menu` component.
- Test confirms seeded Food and Drink data are present in page props.
- Seeder/model test confirms 10 foods are seeded, split across both categories, with prices and image paths.

## Verification

- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- Run `php artisan test --compact`.
- Run `npm run types:check`.
- Run `npm run build`.

## Assumptions

- Quantity controls remain UI-only until a cart or ordering flow is requested.
- The generated images are original menu-style assets, not copied brand/product photos.
- `DESIGN.md` will be a concise hand-authored design brief because no local Google Stitch skill exists.

