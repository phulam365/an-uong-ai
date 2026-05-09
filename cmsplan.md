# Filament Food CMS Plan

## Summary

Add a Filament v5 admin panel at `/admin` for managing homepage menu items. The CMS should support CRUD for food and drink records, image uploads, availability controls, and simple ordering.

## Dependencies And Setup

- Install Filament v5 panel builder.
- Run the Filament panel installer to create the admin panel provider.
- Confirm the provider is registered in `bootstrap/providers.php`.
- Keep the admin panel mounted at `/admin`.

## Admin Access

- Add `users.is_admin`.
- Seed an admin user:
  - Email: `admin@example.com`
  - Password: `password`
  - `is_admin`: `true`
- Restrict panel access to admin users by implementing Filament panel authorization on `User`.
- Non-admin users should not be able to access the admin panel.

## Food Resource

- Create `FoodResource` for the `Food` model.
- Support list, create, edit, and delete.
- Keep the resource focused on menu management, not order management.

## Form Fields

- `name`: text input, required.
- `slug`: generated or editable slug, required and unique.
- `category`: select with fixed values:
  - `food`
  - `drink`
- `ingredients`: textarea.
- `price_vnd`: numeric money input, required.
- `image_path`: image upload stored in the public menu storage location.
- `is_available`: toggle.
- `sort_order`: numeric input for display ordering.

## Table Columns

- Image thumbnail.
- Name.
- Category.
- Price.
- Availability status.
- Sort order.
- Updated date.

## Data Ownership

- The CMS manages the same `foods` table consumed by the public app page.
- Admin uploads should write paths compatible with the public `image_url` prop.
- Keep categories fixed and simple unless future requirements need category CRUD.

## Tests

- Filament resource smoke test confirms an admin user can load the food list page.
- Access test confirms a non-admin user cannot access the admin panel.
- CRUD-focused tests should cover creating or updating a menu item if resource behavior becomes customized beyond Filament defaults.

## Verification

- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- Run `php artisan test --compact`.
- Run `npm run types:check` if generated routes or frontend types are affected.
- Run `npm run build` if frontend assets are affected.

## References

- https://filamentphp.com/docs/5.x/introduction/installation
- https://filamentphp.com/docs/5.x/resources/overview
- https://filamentphp.com/docs/5.x/forms/file-upload
- https://laravel.com/docs/13.x/filesystem

