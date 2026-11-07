# DTB Media Module

This module folder hosts the target bounded architecture for media/image sync concerns.

Current runtime behavior remains served by temporary compatibility wrappers that delegate to legacy `dtb-image-sync.php` until implementation is fully extracted.

The admin Image Sync pathway selector includes `2026/schematics` as a fixed Schematics-owned option. That pathway never enters product-image registration or WooCommerce gallery linking. The `dtb-media` admin transport verifies its own nonce/capability, additionally requires the Schematics capability, and delegates registration/page/product relationship work to `dtb_schematic_run_operation()`.
