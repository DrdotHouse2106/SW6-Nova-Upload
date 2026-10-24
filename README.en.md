# Nova Upload for Shopware 6

🇩🇪 [Deutsche Version](README.md)

**File uploads** for Shopware 6.6 and 6.7 – in the **contact form** (e.g. photos, drawings or 3D files for a request) and on **products** (e.g. the logo for a custom product).

Files are **stored protected in the shop**: they live in Shopware's private storage, have no public URL and can only be downloaded after logging into the administration. Mails contain no attachments, only the file names and a link to the administration. Expired files are deleted automatically.

## Features

- **Contact form:** Shopware's own contact form (shopping experience element "Form", type contact) gets an "Attach files" field – per sales channel.
- **Products:** enable per product via custom fields "File upload (Nova Upload)": upload field above the add-to-cart button, optionally required, own label and hint. The same product with a different file becomes its own cart position. File names are shown in cart, checkout and the customer's orders.
- **Download area** in the customer account for enabled accounts (e.g. production) without admin access – password plus authenticator code (Nova 2FA plugin required).
- **Optional consent** checkbox "part may be added to the range".
- **Required uploads in listings** show "Details" instead of "Add to cart".
- **Administration:** *Orders → Uploads* lists all files (customer, subject/product, order number, search, filter, download, delete). Each order shows a card "Uploaded files".
- **Validation** of count, size per file and total size in the browser and on the server; only allowed types (images, graphics, documents, ZIP, 3D/CAD – no executables, scripts or HTML).
- **Retention:** contact files 180 days, files from carts without order 14 days, order files kept (all configurable).

## Security

Private file system (`files/plugins/nova_upload/`), stored without extension under a random ID, download only for admin users with "view orders" (always as attachment), delete with "delete orders", no mail attachments, sanitized file names, automatic deletion.

## Installation

Via the GitHub plugin updater catalog, or:

```bash
cd <shopware-root>/custom/plugins
git clone https://github.com/DrdotHouse2106/SW6-Nova-Upload.git NovaUpload
cd <shopware-root>
bin/console plugin:refresh
bin/console plugin:install --activate NovaUpload
bin/console theme:compile
bin/console cache:clear
```

Scheduled tasks must run for automatic deletion.

## Troubleshooting

- **Upload fails or the form hangs:** raise PHP `upload_max_filesize` (per file) and `post_max_size` (above the total size).
- **Upload field missing in the contact form:** enable it for the right sales channel, use form type "Contact", clear the cache.
- **Upload field shown twice:** update Nova Theme to 1.8.0 or later (1.7.x had its own contact upload).
- **Upload field missing on the product page:** enable the custom field on the main product; the field is only shown where the buy button is.
- **Files are not deleted:** scheduled tasks are not running (`nova_upload.cleanup`).

## License

MIT
