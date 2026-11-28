Drywall Toolbox manual SiteGround backend file bundle
Source commit: 49bf06d6b9bef01e0894698abc5b4491c07f345d

Contents are paths relative to the production WordPress Git root (/wp).
Restore/upload these files under /wp using the same relative paths.
The live EbayFulfillmentService.php was observed with two stray text lines
before <?php. Replace it with the included canonical source to restore clean
WordPress login output and authentication cookies.

The root frontend .htaccess redirect is not in this bundle. It was already
published by production workflow 45459003802; its deployed artifact/hash
verification succeeded.
