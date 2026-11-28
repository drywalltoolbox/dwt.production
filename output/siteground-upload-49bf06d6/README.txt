Drywall Toolbox manual SiteGround backend file bundle
Source commit: 49bf06d6b9bef01e0894698abc5b4491c07f345d

All paths in this bundle are relative to the production WordPress root (/wp).
Copy each file to its matching relative path. The critical login repair is
wp-content/mu-plugins/dtb-integrations/Ebay/EbayFulfillmentService.php; the
live copy was observed with two stray text lines before <?php. This package
contains the canonical source version, which removes that output.

The other seven PHP files are the backend source changes included in the
pushed checkpoint. The production frontend root .htaccess redirect was already
published separately by successful workflow 45459003802.
