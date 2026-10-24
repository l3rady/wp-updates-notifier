<?php
/**
 * Turn a WordPress `.l10n.php` translation file into a PO file, on standard output.
 *
 *   php bin/l10n-to-po.php languages/wp-updates-notifier-de_DE.l10n.php [TEMPLATE.pot] > de_DE.po
 *
 * The `.l10n.php` files are the plugin's source of truth and `bin/translations.sh` uses this to get a PO file to edit.
 * The format is WordPress's own (wp-includes/l10n/class-wp-translation-file-php.php): a `messages` array that maps the
 * original string to its translation, with the other keys as headers.
 *
 * - A context is joined to the original with "\4": `context\4original`.
 * - The key of a plural entry is the singular alone, and its translations (one per plural form) are joined with "\0".
 *   The file does not keep the plural original, nor tell a plural entry of a language with one plural form (such as
 *   Japanese) from a singular one. So the optional template (written with `msgcat --no-wrap`, one line per string)
 *   says which entries are plural and gives their plural original, and without it a plural entry gets the singular in
 *   its place. Without the right plural original `msgmerge` would mark the entry fuzzy.
 *
 * It writes no translators comments, locations or flags either: `msgmerge` adds those from the template.
 *
 * @package wp-updates-notifier
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

/**
 * Quote a string for a PO file.
 *
 * @param string $text Text.
 * @return string
 */
function wpun_po_quote( $text ) {
	return '"' . strtr(
		$text,
		array(
			'\\' => '\\\\',
			'"'  => '\\"',
			"\n" => '\\n',
			"\t" => '\\t',
			"\r" => '\\r',
		)
	) . '"';
}

/**
 * The plural originals of a template with one line per string, by key ("context\4original").
 *
 * @param string $file Path of the template, or an empty string.
 * @return array<string, string>
 */
function wpun_template_plurals( $file ) {
	$plurals = array();
	if ( '' === $file ) {
		return $plurals;
	}

	$context = '';
	$key     = '';
	foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
		if ( preg_match( '/^(msgctxt|msgid|msgid_plural) "(.*)"$/', $line, $match ) ) {
			$text = stripcslashes( $match[2] );
			if ( 'msgctxt' === $match[1] ) {
				$context = $text . "\4";
			} elseif ( 'msgid' === $match[1] ) {
				$key = $context . $text;
			} else {
				$plurals[ $key ] = $text;
			}
		} elseif ( '' === $line ) {
			$context = '';
			$key     = '';
		}
	}

	return $plurals;
}

/**
 * The PO file of a decoded `.l10n.php` file.
 *
 * @param array                $data    The array the file returns.
 * @param array<string,string> $plurals Plural originals of the template, by key.
 * @return string
 */
function wpun_l10n_to_po( array $data, array $plurals = array() ) {
	$header = array(
		'Project-Id-Version: ' . ( $data['project-id-version'] ?? '' ),
		'MIME-Version: 1.0',
		'Content-Type: text/plain; charset=UTF-8',
		'Content-Transfer-Encoding: 8bit',
		'Language: ' . ( $data['language'] ?? '' ),
		'Plural-Forms: ' . ( $data['plural-forms'] ?? '' ),
		'X-Domain: ' . ( $data['domain'] ?? '' ),
	);

	$po = "msgid \"\"\nmsgstr \"\"\n";
	foreach ( $header as $line ) {
		$po .= wpun_po_quote( $line . "\n" ) . "\n";
	}

	foreach ( (array) ( $data['messages'] ?? array() ) as $key => $translation ) {
		$key     = (string) $key;
		$plural  = $plurals[ $key ] ?? null;
		$context = null;
		if ( false !== strpos( $key, "\4" ) ) {
			list( $context, $key ) = explode( "\4", $key, 2 );
		}

		$po .= "\n";
		if ( null !== $context ) {
			$po .= 'msgctxt ' . wpun_po_quote( $context ) . "\n";
		}
		$po .= 'msgid ' . wpun_po_quote( $key ) . "\n";

		$forms = explode( "\0", (string) $translation );
		if ( null !== $plural || count( $forms ) > 1 ) {
			$po .= 'msgid_plural ' . wpun_po_quote( $plural ?? $key ) . "\n";
			foreach ( $forms as $index => $form ) {
				$po .= 'msgstr[' . $index . '] ' . wpun_po_quote( $form ) . "\n";
			}
		} else {
			$po .= 'msgstr ' . wpun_po_quote( $forms[0] ) . "\n";
		}
	}

	return $po;
}

if ( isset( $argv[0] ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	$file = $argv[1] ?? '';
	if ( '' === $file || ! is_file( $file ) ) {
		fwrite( STDERR, "Usage: php bin/l10n-to-po.php FILE.l10n.php\n" );
		exit( 1 );
	}
	$data = require $file;
	if ( ! is_array( $data ) ) {
		fwrite( STDERR, "$file does not return an array.\n" );
		exit( 1 );
	}
	echo wpun_l10n_to_po( $data, wpun_template_plurals( $argv[2] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A PO file, not HTML.
}
