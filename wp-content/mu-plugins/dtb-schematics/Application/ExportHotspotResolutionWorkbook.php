<?php
/**
 * DTB Schematics — consolidated hotspot resolution workbook export.
 *
 * Produces one operator-facing XLSX audit workbook from the same immutable
 * resolution-plan payload used by the Hotspot Resolver. This is an export-only
 * projection and never becomes application state or mutation authority.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build one XLSX workbook for a hotspot resolution run.
 *
 * @return array|WP_Error {path:string,filename:string}
 */
function dtb_schematic_hotspot_resolution_workbook_create( array $payload ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'dtb_hotspot_xlsx_zip_missing', __( 'The PHP ZipArchive extension is required to export the hotspot audit workbook.', 'drywall-toolbox' ) );
	}

	$run  = (array) ( $payload['run'] ?? [] );
	$plan = (array) ( $payload['plan'] ?? [] );
	$run_id = sanitize_file_name( (string) ( $run['id'] ?? 'run' ) );
	$filename = 'dtb-hotspot-resolution-audit-' . $run_id . '.xlsx';
	$tmp = wp_tempnam( $filename );
	if ( ! is_string( $tmp ) || '' === $tmp ) {
		return new WP_Error( 'dtb_hotspot_xlsx_temp_failed', __( 'Unable to allocate a temporary workbook file.', 'drywall-toolbox' ) );
	}

	$sheets = dtb_schematic_hotspot_resolution_workbook_sheets( $payload );
	$zip = new ZipArchive();
	$opened = $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	if ( true !== $opened ) {
		@unlink( $tmp );
		return new WP_Error( 'dtb_hotspot_xlsx_open_failed', __( 'Unable to create the hotspot audit workbook archive.', 'drywall-toolbox' ) );
	}

	$zip->addFromString( '[Content_Types].xml', dtb_schematic_hotspot_xlsx_content_types( count( $sheets ) ) );
	$zip->addFromString( '_rels/.rels', dtb_schematic_hotspot_xlsx_root_rels() );
	$zip->addFromString( 'docProps/core.xml', dtb_schematic_hotspot_xlsx_core_props( $run_id ) );
	$zip->addFromString( 'docProps/app.xml', dtb_schematic_hotspot_xlsx_app_props( array_column( $sheets, 'name' ) ) );
	$zip->addFromString( 'xl/workbook.xml', dtb_schematic_hotspot_xlsx_workbook( array_column( $sheets, 'name' ) ) );
	$zip->addFromString( 'xl/_rels/workbook.xml.rels', dtb_schematic_hotspot_xlsx_workbook_rels( count( $sheets ) ) );
	$zip->addFromString( 'xl/styles.xml', dtb_schematic_hotspot_xlsx_styles() );

	foreach ( $sheets as $index => $sheet ) {
		$zip->addFromString(
			'xl/worksheets/sheet' . ( $index + 1 ) . '.xml',
			dtb_schematic_hotspot_xlsx_sheet_xml( (array) $sheet )
		);
	}
	$zip->close();

	return [ 'path' => $tmp, 'filename' => $filename ];
}

/** Build the normalized workbook sheets from one plan payload. */
function dtb_schematic_hotspot_resolution_workbook_sheets( array $payload ): array {
	$run     = (array) ( $payload['run'] ?? [] );
	$plan    = (array) ( $payload['plan'] ?? [] );
	$summary = (array) ( $plan['summary'] ?? [] );

	$summary_rows = [
		[ 'DTB Schematic Hotspot Resolution Audit', '', '', '' ],
		[ 'Run ID', (string) ( $run['id'] ?? '' ), 'Mode', (string) ( $plan['mode'] ?? '' ) ],
		[ 'Status', (string) ( $plan['status'] ?? '' ), 'Can Apply', ! empty( $plan['can_apply'] ) ? 'Yes' : 'No' ],
		[ 'Generated At', (string) ( $plan['generated_at'] ?? '' ), 'Schema Version', (int) ( $plan['schema_version'] ?? 0 ) ],
		[ 'Fingerprint', (string) ( $plan['fingerprint'] ?? '' ), 'Run Error', (string) ( $run['error'] ?? '' ) ],
		[ '', '', '', '' ],
		[ 'Coverage Metric', 'Value', 'Resolution Metric', 'Value' ],
		[ 'Schematics examined', (int) ( $summary['schematics_examined'] ?? 0 ), 'Projected new mappings', (int) ( $summary['projected_new_mappings'] ?? 0 ) ],
		[ 'Source files', (int) ( $summary['source_files'] ?? 0 ), 'Applied new mappings', (int) ( $summary['applied_new_mappings'] ?? 0 ) ],
		[ 'Source parts', (int) ( $summary['source_parts'] ?? 0 ), 'Exactly resolvable signal', (int) ( $summary['exactly_resolvable_signal'] ?? 0 ) ],
		[ 'Hotspot occurrences', (int) ( $summary['hotspot_occurrences'] ?? 0 ), 'Active unresolved', (int) ( $summary['active_hotspot_unresolved'] ?? 0 ) ],
		[ 'Source read errors', (int) ( $summary['source_read_errors'] ?? 0 ), 'Catalog-only unresolved', (int) ( $summary['catalog_only_unresolved'] ?? 0 ) ],
		[ 'Source unavailable', (int) ( $summary['source_unavailable'] ?? 0 ), 'Resolution groups', (int) ( $summary['resolution_groups'] ?? 0 ) ],
		[ 'Partial source records', (int) ( $summary['source_partial_records'] ?? 0 ), 'Catalog correction groups', (int) ( $summary['catalog_correction_groups'] ?? 0 ) ],
		[ 'Invalid source records', (int) ( $summary['source_invalid_records'] ?? 0 ), 'Source correction groups', (int) ( $summary['source_correction_groups'] ?? 0 ) ],
		[ 'Source drift', (int) ( $summary['source_drift'] ?? 0 ), 'Manual review groups', (int) ( $summary['manual_review_groups'] ?? 0 ) ],
	];

	$summary_rows[] = [ '', '', '', '' ];
	$summary_rows[] = [ 'Disposition', 'Relationship Count', 'Reason', 'Count' ];
	$dispositions = (array) ( $plan['disposition_counts'] ?? [] );
	$reasons      = (array) ( $plan['reason_counts'] ?? [] );
	$max = max( count( $dispositions ), count( $reasons ) );
	$disp_keys = array_keys( $dispositions );
	$reason_keys = array_keys( $reasons );
	for ( $i = 0; $i < $max; $i++ ) {
		$dk = $disp_keys[ $i ] ?? '';
		$rk = $reason_keys[ $i ] ?? '';
		$summary_rows[] = [
			(string) $dk,
			'' !== $dk ? (int) $dispositions[ $dk ] : '',
			(string) $rk,
			'' !== $rk ? (int) $reasons[ $rk ] : '',
		];
	}
	if ( ! empty( $plan['blockers'] ) ) {
		$summary_rows[] = [ '', '', '', '' ];
		$summary_rows[] = [ 'Blocking Code', 'Blocking Message', '', '' ];
		foreach ( (array) $plan['blockers'] as $blocker ) {
			$summary_rows[] = [ (string) ( $blocker['code'] ?? '' ), (string) ( $blocker['message'] ?? '' ), '', '' ];
		}
	}

	$plan_headers = [ 'Disposition','Issue Code','Issue Label','Brand','Part Ref','Source SKU','Source Display ID','Part Name','Relationships','Hotspot Occurrences','Affected Schematics','Candidate Evidence','Required Action' ];
	$plan_rows = [ $plan_headers ];
	foreach ( (array) ( $plan['resolution_groups'] ?? [] ) as $group ) {
		$row = dtb_schematic_hotspot_plan_artifact_row( (array) $group );
		$plan_rows[] = array_values( $row );
	}

	$artifact_sheet = static function ( array $rows ) use ( $plan_headers ): array {
		$out = [ $plan_headers ];
		foreach ( $rows as $row ) {
			$out[] = array_values( (array) $row );
		}
		return $out;
	};

	$mapping_rows = [ [ 'Schematic','Part Ref','Source SKU','Source Name','Product ID','Product SKU','Product Name','Product Brand','Resolution Method','Hotspot Occurrences' ] ];
	foreach ( (array) ( $plan['proposed_mappings'] ?? [] ) as $repair ) {
		$product = (array) ( $repair['product'] ?? [] );
		$mapping_rows[] = [
			(string) ( $repair['canonical_id'] ?? $repair['schematic_id'] ?? '' ),
			(string) ( $repair['part_ref'] ?? '' ),
			(string) ( $repair['source_sku'] ?? '' ),
			(string) ( $repair['title'] ?? $repair['name'] ?? '' ),
			(int) ( $repair['product_id'] ?? 0 ),
			(string) ( $product['sku'] ?? '' ),
			(string) ( $product['name'] ?? '' ),
			(string) ( $product['brand'] ?? '' ),
			(string) ( $repair['resolution_method'] ?? '' ),
			(int) ( $repair['occurrences'] ?? 0 ),
		];
	}

	$source_rows = [ [ 'Schematic','Source Status','Source Drift','Source Files','Source Parts','Source Hotspots','Exactly Resolvable','Unresolved','Source Error' ] ];
	foreach ( (array) ( $plan['record_results'] ?? [] ) as $record ) {
		$files = $record['source_files'] ?? $record['files'] ?? [];
		if ( is_array( $files ) ) {
			$files = implode( '|', array_map( 'strval', $files ) );
		}
		$source_rows[] = [
			(string) ( $record['canonical_id'] ?? $record['schematic_id'] ?? '' ),
			(string) ( $record['source_status'] ?? '' ),
			! empty( $record['source_drift'] ) ? 'Yes' : 'No',
			(string) $files,
			(int) ( $record['source_parts'] ?? $record['parts'] ?? 0 ),
			(int) ( $record['source_hotspots'] ?? $record['hotspots'] ?? 0 ),
			(int) ( $record['exactly_resolvable'] ?? $record['exact_resolvable'] ?? 0 ),
			(int) ( $record['unresolved'] ?? 0 ),
			(string) ( $record['source_error'] ?? $record['error'] ?? '' ),
		];
	}

	$metadata_rows = [ [ 'Key', 'Value' ] ];
	$metadata_rows[] = [ 'report_type', (string) ( $payload['report_type'] ?? '' ) ];
	foreach ( $run as $key => $value ) {
		$metadata_rows[] = [ 'run.' . (string) $key, dtb_schematic_hotspot_xlsx_scalarize( $value ) ];
	}
	foreach ( (array) ( $payload['raw_optimizer_metrics'] ?? [] ) as $key => $value ) {
		$metadata_rows[] = [ 'optimizer.' . (string) $key, dtb_schematic_hotspot_xlsx_scalarize( $value ) ];
	}
	foreach ( (array) ( $plan['source_errors'] ?? [] ) as $index => $value ) {
		$metadata_rows[] = [ 'source_error.' . ( $index + 1 ), dtb_schematic_hotspot_xlsx_scalarize( $value ) ];
	}

	$artifacts = (array) ( $plan['artifacts'] ?? [] );
	return [
		[ 'name' => 'Summary', 'rows' => $summary_rows, 'widths' => [ 28, 28, 34, 18 ], 'freeze' => 1, 'title_rows' => [ 1 ], 'header_rows' => [ 7, 18 ] ],
		[ 'name' => 'Resolution Plan', 'rows' => $plan_rows, 'widths' => [ 24,24,30,18,18,18,20,32,14,18,42,46,58 ], 'freeze' => 1, 'header_rows' => [ 1 ], 'filter' => true ],
		[ 'name' => 'Catalog Corrections', 'rows' => $artifact_sheet( (array) ( $artifacts['catalog_corrections'] ?? [] ) ), 'widths' => [ 24,24,30,18,18,18,20,32,14,18,42,46,58 ], 'freeze' => 1, 'header_rows' => [ 1 ], 'filter' => true ],
		[ 'name' => 'Source Corrections', 'rows' => $artifact_sheet( (array) ( $artifacts['source_corrections'] ?? [] ) ), 'widths' => [ 24,24,30,18,18,18,20,32,14,18,42,46,58 ], 'freeze' => 1, 'header_rows' => [ 1 ], 'filter' => true ],
		[ 'name' => 'Manual Review', 'rows' => $artifact_sheet( (array) ( $artifacts['manual_review'] ?? [] ) ), 'widths' => [ 24,24,30,18,18,18,20,32,14,18,42,46,58 ], 'freeze' => 1, 'header_rows' => [ 1 ], 'filter' => true ],
		[ 'name' => 'Deterministic Mappings', 'rows' => $mapping_rows, 'widths' => [ 34,20,20,36,14,20,38,24,24,18 ], 'freeze' => 1, 'header_rows' => [ 1 ], 'filter' => true ],
		[ 'name' => 'Source Audit', 'rows' => $source_rows, 'widths' => [ 38,18,14,48,14,16,18,14,52 ], 'freeze' => 1, 'header_rows' => [ 1 ], 'filter' => true ],
		[ 'name' => 'Run Metadata', 'rows' => $metadata_rows, 'widths' => [ 36, 80 ], 'freeze' => 1, 'header_rows' => [ 1 ] ],
	];
}

function dtb_schematic_hotspot_xlsx_scalarize( $value ): string {
	if ( is_bool( $value ) ) {
		return $value ? 'true' : 'false';
	}
	if ( is_scalar( $value ) || null === $value ) {
		return (string) $value;
	}
	return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
}

function dtb_schematic_hotspot_xlsx_sheet_xml( array $sheet ): string {
	$rows = array_values( (array) ( $sheet['rows'] ?? [] ) );
	$widths = array_values( (array) ( $sheet['widths'] ?? [] ) );
	$title_rows = array_map( 'intval', (array) ( $sheet['title_rows'] ?? [] ) );
	$header_rows = array_map( 'intval', (array) ( $sheet['header_rows'] ?? [] ) );
	$max_cols = 1;
	foreach ( $rows as $row ) {
		$max_cols = max( $max_cols, count( (array) $row ) );
	}
	$max_rows = max( 1, count( $rows ) );
	$last_cell = dtb_schematic_hotspot_xlsx_col_name( $max_cols ) . $max_rows;

	$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
	$xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
	$xml .= '<dimension ref="A1:' . esc_attr( $last_cell ) . '"/>';
	$freeze = (int) ( $sheet['freeze'] ?? 0 );
	if ( $freeze > 0 ) {
		$xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $freeze . '" topLeftCell="A' . ( $freeze + 1 ) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
	} else {
		$xml .= '<sheetViews><sheetView workbookViewId="0"/></sheetViews>';
	}
	$xml .= '<sheetFormatPr defaultRowHeight="18"/>';
	if ( $widths ) {
		$xml .= '<cols>';
		foreach ( $widths as $i => $width ) {
			$n = $i + 1;
			$xml .= '<col min="' . $n . '" max="' . $n . '" width="' . max( 8, min( 90, (float) $width ) ) . '" customWidth="1"/>';
		}
		$xml .= '</cols>';
	}
	$xml .= '<sheetData>';
	foreach ( $rows as $r_index => $row ) {
		$row_num = $r_index + 1;
		$xml .= '<row r="' . $row_num . '">';
		foreach ( array_values( (array) $row ) as $c_index => $value ) {
			$cell_ref = dtb_schematic_hotspot_xlsx_col_name( $c_index + 1 ) . $row_num;
			$style = in_array( $row_num, $title_rows, true ) ? 1 : ( in_array( $row_num, $header_rows, true ) ? 2 : 3 );
			$xml .= dtb_schematic_hotspot_xlsx_cell_xml( $cell_ref, $value, $style );
		}
		$xml .= '</row>';
	}
	$xml .= '</sheetData>';
	if ( ! empty( $sheet['filter'] ) && $max_rows >= 1 ) {
		$xml .= '<autoFilter ref="A1:' . dtb_schematic_hotspot_xlsx_col_name( $max_cols ) . $max_rows . '"/>';
	}
	$xml .= '<pageMargins left="0.4" right="0.4" top="0.6" bottom="0.6" header="0.2" footer="0.2"/>';
	$xml .= '</worksheet>';
	return $xml;
}

function dtb_schematic_hotspot_xlsx_cell_xml( string $ref, $value, int $style ): string {
	if ( is_int( $value ) || is_float( $value ) ) {
		return '<c r="' . esc_attr( $ref ) . '" s="' . $style . '" t="n"><v>' . esc_html( (string) $value ) . '</v></c>';
	}
	$text = dtb_schematic_hotspot_xlsx_clean_text( dtb_schematic_hotspot_xlsx_scalarize( $value ) );
	return '<c r="' . esc_attr( $ref ) . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . esc_html( $text ) . '</t></is></c>';
}

function dtb_schematic_hotspot_xlsx_clean_text( string $text ): string {
	$text = preg_replace( '/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $text );
	return is_string( $text ) ? $text : '';
}

function dtb_schematic_hotspot_xlsx_col_name( int $number ): string {
	$name = '';
	while ( $number > 0 ) {
		$number--;
		$name = chr( 65 + ( $number % 26 ) ) . $name;
		$number = intdiv( $number, 26 );
	}
	return $name;
}

function dtb_schematic_hotspot_xlsx_content_types( int $sheet_count ): string {
	$overrides = '';
	for ( $i = 1; $i <= $sheet_count; $i++ ) {
		$overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
	}
	return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>' . $overrides . '</Types>';
}

function dtb_schematic_hotspot_xlsx_root_rels(): string {
	return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>';
}

function dtb_schematic_hotspot_xlsx_workbook( array $names ): string {
	$sheets = '';
	foreach ( array_values( $names ) as $i => $name ) {
		$sheets .= '<sheet name="' . esc_attr( substr( (string) $name, 0, 31 ) ) . '" sheetId="' . ( $i + 1 ) . '" r:id="rId' . ( $i + 1 ) . '"/>';
	}
	return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $sheets . '</sheets></workbook>';
}

function dtb_schematic_hotspot_xlsx_workbook_rels( int $sheet_count ): string {
	$rels = '';
	for ( $i = 1; $i <= $sheet_count; $i++ ) {
		$rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
	}
	$rels .= '<Relationship Id="rId' . ( $sheet_count + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
	return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
}

function dtb_schematic_hotspot_xlsx_styles(): string {
	return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="10"/><name val="Aptos"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="14"/><name val="Aptos Display"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Aptos"/></font></fonts><fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0B1835"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1769FF"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border/><border><left style="thin"><color rgb="FFD7DCE5"/></left><right style="thin"><color rgb="FFD7DCE5"/></right><top style="thin"><color rgb="FFD7DCE5"/></top><bottom style="thin"><color rgb="FFD7DCE5"/></bottom></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFill="1" applyFont="1" applyAlignment="1"><alignment vertical="center"/></xf><xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFill="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
}

function dtb_schematic_hotspot_xlsx_core_props( string $run_id ): string {
	$now = esc_html( gmdate( 'Y-m-d\TH:i:s\Z' ) );
	return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>DTB Hotspot Resolution Audit</dc:title><dc:subject>Run ' . esc_html( $run_id ) . '</dc:subject><dc:creator>Drywall Toolbox</dc:creator><cp:lastModifiedBy>Drywall Toolbox</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified></cp:coreProperties>';
}

function dtb_schematic_hotspot_xlsx_app_props( array $names ): string {
	$parts = '';
	foreach ( $names as $name ) {
		$parts .= '<vt:lpstr>' . esc_html( (string) $name ) . '</vt:lpstr>';
	}
	return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Drywall Toolbox</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop><HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>' . count( $names ) . '</vt:i4></vt:variant></vt:vector></HeadingPairs><TitlesOfParts><vt:vector size="' . count( $names ) . '" baseType="lpstr">' . $parts . '</vt:vector></TitlesOfParts><Company>Drywall Toolbox</Company><LinksUpToDate>false</LinksUpToDate><SharedDoc>false</SharedDoc><HyperlinksChanged>false</HyperlinksChanged><AppVersion>1.0</AppVersion></Properties>';
}
