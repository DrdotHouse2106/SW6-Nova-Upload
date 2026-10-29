# 1.4.0
- Fixed: product file upload did not take effect (the check ran before Shopware resolved the sales channel). Files are validated and stored again, "File is required" now blocks adding without a file
- Security: quota for product uploads – max. 20 files per visitor (IP) and hour, new uploads are rejected while cart files without order exceed 2048 MB (both configurable, 0 = no limit)
- Security: upload data in the cart is only set by the server; an order only gets files uploaded for exactly that position
- Security: content is checked against the extension (images, PDF), HTML/executable content and SVG with scripts are rejected
- Privacy: deleting a customer account removes its requests and cart files (not for guest accounts and not while another account uses the e-mail address); deleting an order removes its files
- Privacy: order files are deleted after 730 days by default. The update only sets this when "0 = keep" was configured; a custom period stays
- Cleanup now removes all expired files instead of 500 per run; a failed save no longer leaves an orphaned file

# 1.3.0
- New: contact form upload label and hint per sales channel (placeholders %count%, %size%)

# 1.2.0
- New: select customers with access to the download area directly in the plugin configuration (search by e-mail); the customer custom field still works

# 1.1.0
- New: download area in the customer account for enabled accounts, protected by password and authenticator code (Nova 2FA plugin required)
- Contact mail links to the area
- New: optional consent checkbox "part may be added to the range", shown in mail, download area and administration

# 1.0.1
- New plugin icon in Nova style

# 1.0.0
- First version: file upload in the contact form and on products (per product via custom fields, optionally required), protected storage in the private file system, overview under Orders → Uploads and in the order, notice in the contact mail, automatic deletion after retention periods
