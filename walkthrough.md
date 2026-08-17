# Walkthrough: Fast UPI Generation, CSV Import/Export, Shopify Auth Resilience, Sync Key Fix, Store Name Support, OAuth Callback Resilience, Cross-Store Sync Fixes, Metafield Management, and AI Category Classification

We have successfully optimized the UPI generation speed, exposed the product CSV import/export buttons prominently on the Livewire dashboard, implemented robustness fixes to handle invalid or revoked Shopify credentials gracefully, resolved a critical bug where background synchronization jobs queried the wrong metafield key, added full support for displaying human-readable store names, implemented robust protection against duplicate/expired Shopify OAuth callback exchange requests, disabled cross-store propagation of metafields, added Artisan commands to clear, generate, pin, and delete metafield definitions, and built an automated system that imports category paths from `Category.xlsx` and uses Google Gemini AI to auto-classify products.

---

## Key Achievements & Modifications

### 1. Shopify Sync Key Fix (Unrecognized Metafields Fix)

- **The Problem**:
  - The `SyncInitialProductsJob` (dispatched during onboarding or when manually clicking "Sync Products" in the dashboard) was querying the deprecated metafield key `"upi_code"` instead of `"upi"`.
  - As a result, when the sync ran, it received `null` from Shopify's GraphQL API and overwrote the local database record's correct `upi_code` value with `null`.
- **The Solution**:
  - Modified [SyncInitialProductsJob.php](file:///Users/radhe/Documents/shopify_app/app/Jobs/SyncInitialProductsJob.php) to query `"upi"` (under alias `upi_code`), ensuring that synced values are successfully mapped and preserved.

### 2. Shopify Auth & Token Revocation Resilience

- **Automatic Store Inactivation**:
  - Modified [ShopifyClient.php](file:///Users/radhe/Documents/shopify_app/app/Services/ShopifyClient.php) to inspect API responses. If a `401 Unauthorized` status or an error message containing `Invalid API key or access token` is detected, the client automatically updates the shop's status to `inactive` in the local database.
- **Graceful Livewire Error Handling**:
  - Modified [Dashboard.php](file:///Users/radhe/Documents/shopify_app/app/Livewire/Dashboard.php)'s main actions (`syncStoreProducts()`, `generateMissingUpis()`, `submitBulkEdit()`, `saveInlineUpi()`) to wrap dispatches and service calls in `try/catch` blocks. Surfaced errors as clear session flash warning messages instead of throwing raw 500 exceptions.

### 3. Fast UPI Generation ($O(1)$ Optimization)

- **In-Memory Uniqueness Map**:
  - Rewrote the uniqueness verification loop in `Dashboard.php`'s `generateMissingUpis()`. Instead of executing an $O(N)$ sequence of database queries (`Product::where('upi_code', ...)->exists()`) for every product, we fetch all existing database UPIs at the start in a single query and flip them into an in-memory hash map. Lookups are now $O(1)$.
- **Bulk Database Transaction**:
  - Accumulated updates in an array and executed them inside a single `DB::transaction()` block.
- **Bulk Shopify Metafield Queuing**:
  - Dispatches a single `BulkPushUpiToShopifyJob` to sync all newly generated UPI codes in one go.

### 4. Bulk Shopify API Syncing (`BulkPushUpiToShopifyJob.php`)

- **Group & Chunk Flow**:
  - Modified the bulk push job to group product updates by shop and chunk them into batches of 8 products (up to 24 metafields) to stay under Shopify's single request limit of 25 metafields.
- **Utilizes the bulk `setBulkMetafields` mutation on `ShopifyClient` to update multiple products' metafields in a single GraphQL request.

### 5. CSV Import & Export Dashboard Integration (`dashboard.blade.php`)

- **Header Bar Exposure**:
  - Added primary "Import CSV" and "Export Filtered" buttons directly in the header of the "Product Inventory Directory" section. They are visible in both standalone and embedded views.
- **Reactive Export Links**:
  - Replaced static/initial query params in the Export link with live references to Livewire's reactive filter properties.
- **Smart Pre-selection**:
  - Configured the Target Store dropdown in the "Import CSV" modal to pre-select the active store when accessed via the Shopify Embedded App view.

### 6. Bulk CSV Import Optimization (`BulkImportCsvJob.php`)

- Optimized the CSV bulk import process to chunk database updates (groups of 100) and dispatch `BulkPushUpiToShopifyJob` in batch arrays instead of queuing separate jobs for every imported row.

---

## 23. Database Category Seeding from Category.xlsx

- **The Request**:
  - Load the categories exactly as specified in `Category.xlsx` (located in the root directory) and use them across the system without modification.
- **The Solution**:
  - **Database Expansion**:
    - Created [2026_07_02_150000_create_product_categories_table.php](file:///Users/radhe/Documents/shopify_app/database/migrations/2026_07_02_150000_create_product_categories_table.php) defining the `product_categories` table (`name`, `parent`, `child`).
    - Created the corresponding ProductCategory model [ProductCategory.php](file:///Users/radhe/Documents/shopify_app/app/Models/ProductCategory.php).
  - **Python helper script**:
    - Created [parse_categories.py](file:///Users/radhe/Documents/shopify_app/app/Console/Commands/parse_categories.py) which parses `Category.xlsx` using the `openpyxl` library and outputs the parsed rows as a JSON array.
  - **Artisan Command**:
    - Created [ShopifyImportCategoriesCommand.php](file:///Users/radhe/Documents/shopify_app/app/Console/Commands/ShopifyImportCategoriesCommand.php) (`php artisan shopify:import-categories`) to invoke the python parser, clear previous categories, and bulk-seed the parsed records into the database.
  - **Searchable Autocomplete Selection**:
    - Modified Filament form layouts in [ProductForm.php](file:///Users/radhe/Documents/shopify_app/app/Filament/Resources/Products/Schemas/ProductForm.php) to use searchable `datalist` autocomplete inputs for `product_type` populated directly from the database's `product_categories` table.

---

## 24. AI-Powered Product Classification Command (Standard Shopify Product Type)

- **The Request**:
  - Re-classify products where the product type is null/empty, matches placeholder values (such as `DEFAULT`, `AMBASSADOR`, `APPAREL`, `RETAIL`), or is not in the imported list of categories. Provide a suitable category according to the list, or generate a new category path matching the existing style, and assign it to the Shopify **product type** (instead of a custom item category metafield).
- **The Solution**:
  - Configured the Artisan command [ShopifyClassifyProductsCommand.php](file:///Users/radhe/Documents/shopify_app/app/Console/Commands/ShopifyClassifyProductsCommand.php):
    ```bash
    php artisan shopify:classify-products {shop_domain?} [--force] [--limit=]
    ```
    - **How it Works**:
    1. **Url Parsing**: Cleans up the input shop link to find the matching shop domain name in the database.
    2. **Mismatch Detection**: The query selects products where `product_type` is empty, null, contains a placeholder, or **is not present in the valid `product_categories` database list**.
    3. **Interactive Confirmation**: Before calling the API or starting the process, the command displays the count of mismatched/uncategorized products and prompts for interactive user confirmation.
    4. **Strict Prompting with Resiliency**: The prompt instructs the Google Gemini 2.5 Flash API to strictly choose the closest or best matching category path from the valid categories list. To handle transient network drops and slow response times, the HTTP requests are configured with a **120-second timeout** and will automatically **retry up to 3 times** before skipping a batch.
    5. **Syncing**: Product status changes to `pending_push`. Both [ProductSyncService.php](file:///Users/radhe/Documents/shopify_app/app/Services/ProductSyncService.php) and [BulkPushUpiToShopifyJob.php](file:///Users/radhe/Documents/shopify_app/app/Jobs/BulkPushUpiToShopifyJob.php) call the GraphQL `productUpdate` mutation to update the standard `productType` field on Shopify.
    6. **CSV Log Exporting**: Generates a separate CSV log file (saved to `storage/app/classification/`) for each processed store, containing the Product ID, Product Title, Previous Product Type, and New Product Type.
    7. **Summary Report**: Once the process finishes, the command prints a console table listing the stores that had product types successfully updated along with the count of updated products and the path to their CSV log files.

---

## Verification and Testing

- Verified all 58 tests in the test suite are passing successfully:
  ```bash
  php artisan test
  OK (58 tests, 357 assertions)
  ```

---

## 4. App Bridge CDN & Session Token Integration

To pass the Shopify App Store "Embedded app checks" (Using the latest App Bridge script loaded from Shopify's CDN, and Using session tokens for user authentication):
- **CDN script tag integration**: We conditionally load the CDN App Bridge script tag and the `shopify-api-key` meta tag in [components/layouts/app.blade.php](file:///Users/radhe/Documents/shopify_app/resources/views/components/layouts/app.blade.php) and [dashboard.blade.php](file:///Users/radhe/Documents/shopify_app/resources/views/dashboard.blade.php) when loaded inside Shopify's iframe (detected via query string parameters `host` or `shop`).
- **Session Token authentication**: Loading the CDN script wraps the global `fetch`/`XMLHttpRequest` functions, automatically injecting the required `Authorization: Bearer <JWT>` header on all dashboard backend requests to meet Shopify's compliance checks.

