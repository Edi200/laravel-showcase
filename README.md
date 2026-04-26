# Delivery Platform Showcase

A collection of production code samples extracted from a real-world Laravel 12 project — a multi-vendor food delivery platform built with Laravel 12, Vue 3, Inertia.js, and Filament v5.

## Samples

### ERP Stock Sync API (`StockSyncController`)

A Sanctum-authenticated REST API endpoint that handles real-time stock synchronization from an external Clarion ERP system.

- Validates incoming items (up to 1000 per request)
- Creates new products with auto-assigned categories if not found
- Updates stock, price, name, GTIN, and unit on existing products
- Returns a structured response with `updated`, `created`, and `not_found` counts
- Uses `saveQuietly()` to suppress model events during bulk sync

### Vendor Order Management (`OrderResource` + `ViewOrder`)

A Filament v5 resource for vendors to manage incoming orders in real-time.

- Table with live polling every 5 seconds
- Filters by status, date range, and active/all scope
- Scoped to the authenticated vendor's store via `VendorStore::scopedStoreId()`
- View page with full order details, customer info, delivery task, and order items
- Header actions for order status transitions: `pending → confirmed → preparing → ready`
- Confirmation action includes an estimated prep time input with +/- controls
- Status changes fire Laravel Events (`OrderStatusUpdated`, `DeliveryTaskAvailable`)

### Checkout Page (`Checkout.vue`)

A full checkout page built with Vue 3, TypeScript, and Inertia.js.

- Inertia `useForm` for order submission
- Pinia cart store integration (`useCartStore`) with decimal quantity support
- Saved address selection or manual address input
- Multi-city support with localized city names (Serbian/Hungarian via `vue-i18n`)
- Free delivery threshold indicator
- Address auto-save via fetch before order submission
- Fully typed props and computed state with `canSubmit` guard

## Stack

- Laravel 12
- Vue 3 + TypeScript
- Inertia.js
- Filament v5
- Laravel Sanctum
- Laravel Reverb
- Pinia
- Tailwind CSS + shadcn/ui
- MySQL
