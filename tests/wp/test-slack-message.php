<?php
/**
 * The Slack message: layout, escaping and the limits of Slack.
 *
 * @package wp-updates-notifier
 */

use WPUN\Notification_Item;
use WPUN\Modules\Slack\Slack_Message;

/**
 * Slack_Message, and the same message as the channel sends it.
 */
class Test_Slack_Message extends WPUN_Slack_Test_Case {

	/**
	 * An item.
	 *
	 * @param array $data Values to replace.
	 * @return Notification_Item
	 */
	private function item( array $data = array() ) {
		return new Notification_Item(
			array_merge(
				array(
					'type'            => 'plugin',
					'key'             => 'akismet/akismet.php',
					'name'            => 'Akismet',
					'current_version' => '5.6',
					'new_version'     => '5.7.2',
				),
				$data
			)
		);
	}

	/**
	 * The texts of the section blocks.
	 *
	 * @param array $message A message.
	 * @return string[]
	 */
	private function section_texts( array $message ) {
		$texts = array();
		foreach ( $message['blocks'] as $block ) {
			if ( 'section' === $block['type'] ) {
				$texts[] = $block['text']['text'];
			}
		}

		return $texts;
	}

	/**
	 * Assert the limits of Slack on a message.
	 *
	 * @param array $message A message.
	 */
	private function assert_within_limits( array $message ) {
		$this->assertLessThanOrEqual( 50, count( $message['blocks'] ) );
		$this->assertLessThanOrEqual( 3000, mb_strlen( $message['text'] ) );
		$this->assertSame( 'header', $message['blocks'][0]['type'] );
		$this->assertLessThanOrEqual( 150, mb_strlen( $message['blocks'][0]['text']['text'] ) );
		foreach ( $message['blocks'] as $block ) {
			if ( 'section' === $block['type'] ) {
				$this->assertLessThanOrEqual( 3000, mb_strlen( $block['text']['text'] ) );
				$this->assertSame( 'mrkdwn', $block['text']['type'] );
			}
		}
		$this->assertSame( 'context', $message['blocks'][ count( $message['blocks'] ) - 1 ]['type'] );
		$this->assertIsString( wp_json_encode( $message ) );
	}

	// Shape.

	/**
	 * The message: a header, sections by type, a closing context block, a fallback text and no unfurling.
	 */
	public function test_the_shape() {
		$theme   = $this->item(
			array(
				'type' => 'theme',
				'key'  => 'twentytwenty',
				'name' => 'Twenty Twenty',
			)
		);
		$core    = $this->item(
			array(
				'type'            => 'core',
				'key'             => 'core',
				'name'            => 'WordPress',
				'current_version' => '6.7',
				'new_version'     => '6.8',
			)
		);
		$message = Slack_Message::build( $this->notification_of( array( $theme, $this->item(), $core ) ) );

		$this->assertSame( array( 'text', 'blocks', 'unfurl_links', 'unfurl_media' ), array_keys( $message ) );
		$this->assertFalse( $message['unfurl_links'] );
		$this->assertFalse( $message['unfurl_media'] );
		$this->assertSame( 'There are updates available for My Site: Twenty Twenty 5.7.2, Akismet 5.7.2, WordPress 6.8', $message['text'] );

		$blocks = $message['blocks'];
		$this->assertSame( array( 'header', 'section', 'section', 'section', 'context' ), wp_list_pluck( $blocks, 'type' ) );
		$this->assertSame( 'plain_text', $blocks[0]['text']['type'] );
		$this->assertSame( 'There are updates available for My Site', $blocks[0]['text']['text'] );

		// In the order of the types, whatever the order of the items.
		$this->assertSame( "*WordPress*\n\n*WordPress* from version 6.7 to 6.8", $blocks[1]['text']['text'] );
		$this->assertSame( "*Plugins*\n\n*Akismet* from version 5.6 to 5.7.2", $blocks[2]['text']['text'] );
		$this->assertSame( "*Themes*\n\n*Twenty Twenty* from version 5.6 to 5.7.2", $blocks[3]['text']['text'] );

		$closing = $blocks[4]['elements'];
		$this->assertCount( 1, $closing );
		$this->assertSame( 'mrkdwn', $closing[0]['type'] );
		$this->assertSame( '<https://example.org/wp-admin/update-core.php|Open the Updates screen>  |  <https://example.org|My Site>', $closing[0]['text'] );
		$this->assert_within_limits( $message );
	}

	/**
	 * The title says whether it is a reminder or a test, as the other messages do.
	 */
	public function test_titles() {
		$normal   = Slack_Message::build( $this->notification() );
		$reminder = Slack_Message::build( $this->notification( 1, 'Plugin ', array( 'is_reminder' => true ) ) );
		$test     = Slack_Message::build( $this->notification_of( array(), array( 'is_test' => true ) ) );

		$this->assertSame( 'There are updates available for My Site', $normal['blocks'][0]['text']['text'] );
		$this->assertSame( 'Reminder: these updates are still waiting for My Site', $reminder['blocks'][0]['text']['text'] );
		$this->assertStringStartsWith( 'Reminder: these updates are still waiting for My Site: Plugin 1 2.0', $reminder['text'] );
		$this->assertSame( 'Test message from WP Updates Notifier', $test['blocks'][0]['text']['text'] );
		$this->assertSame( 'Test message from WP Updates Notifier', $test['text'] );
		$this->assertSame( array( 'header', 'section', 'context' ), wp_list_pluck( $test['blocks'], 'type' ) );
		$this->assertSame( 'This is a test message from WP Updates Notifier for My Site (https://example.org). Update notifications for this site will be posted here.', $test['blocks'][1]['text']['text'] );
		$this->assertSame( '<https://example.org|My Site>', $test['blocks'][2]['elements'][0]['text'], 'A test has nothing to update: only the site link.' );
		$this->assert_within_limits( $test );
	}

	/**
	 * The title in the header is cut to 150 characters with an ellipsis.
	 */
	public function test_a_long_site_name_is_cut_in_the_header() {
		$message = Slack_Message::build( $this->notification( 1, 'Plugin ', array(), str_repeat( 'Long site name ', 30 ) ) );

		$header = $message['blocks'][0]['text']['text'];
		$this->assertLessThanOrEqual( 150, mb_strlen( $header ) );
		$this->assertStringEndsWith( '…', $header );
		$this->assertStringStartsWith( 'There are updates available for Long site name', $header );
		$this->assert_within_limits( $message );
	}

	/**
	 * A type an add-on registered is listed after the built-in ones, under its label.
	 */
	public function test_a_custom_type_is_listed_after_the_others() {
		add_filter(
			'sc_wpun_item_types',
			static function ( $types ) {
				$types['php'] = 'PHP';
				return $types;
			}
		);
		$php     = $this->item(
			array(
				'type' => 'php',
				'key'  => 'php',
				'name' => 'PHP 8.1',
			)
		);
		$message = Slack_Message::build( $this->notification_of( array( $php, $this->item() ) ) );

		$this->assertSame( array( "*Plugins*\n\n*Akismet* from version 5.6 to 5.7.2", "*PHP*\n\n*PHP 8.1* from version 5.6 to 5.7.2" ), $this->section_texts( $message ) );
	}

	/**
	 * Links are named after the item, notes follow, and a link that is not a web address is not a link.
	 */
	public function test_links_and_notes() {
		$item    = $this->item(
			array(
				'links' => array(
					'details'   => 'https://wordpress.org/plugins/akismet/',
					'changelog' => 'https://example.org/changelog?a=1&b=2',
					'download'  => 'javascript:alert(1)',
					'extra_one' => 'ftp://example.org/file',
				),
				'notes' => array( 'Tested up to WordPress 6.8', 'Needs PHP 8.0 or newer' ),
			)
		);
		$message = Slack_Message::build( $this->notification_of( array( $item ) ) );

		$this->assertSame(
			"*Plugins*\n\n*Akismet* from version 5.6 to 5.7.2\n"
			. '<https://wordpress.org/plugins/akismet/|Akismet details> | <https://example.org/changelog?a=1&amp;b=2|Akismet changelog>'
			. "\nTested up to WordPress 6.8\nNeeds PHP 8.0 or newer",
			$this->section_texts( $message )[0]
		);
	}

	// Escaping.

	/**
	 * Only `&`, `<` and `>` are replaced, the ampersand first.
	 */
	public function test_escape() {
		$this->assertSame( 'a &amp; b &lt;c&gt; d', Slack_Message::escape( 'a & b <c> d' ) );
		$this->assertSame( '&amp;lt;', Slack_Message::escape( '&lt;' ), 'Entities that were already in the text are escaped too.' );
		$this->assertSame( '&lt;!channel&gt; &lt;@U123|x&gt;', Slack_Message::escape( '<!channel> <@U123|x>' ) );
		$this->assertSame( 'back\\slash *bold* _it_ `code` "quote" \'q\'', Slack_Message::escape( 'back\\slash *bold* _it_ `code` "quote" \'q\'' ), 'Nothing else changes.' );
	}

	/**
	 * Links: `<url|label>` for web addresses, the label alone for anything else, and an address cannot end the link.
	 */
	public function test_link() {
		$this->assertSame( '<https://example.org/a|Label &amp; &lt;more&gt;>', Slack_Message::link( 'https://example.org/a', 'Label & <more>' ) );
		$this->assertSame( '<http://example.org/a|Label>', Slack_Message::link( 'http://example.org/a', 'Label' ) );
		$this->assertSame( 'Label', Slack_Message::link( 'javascript:alert(1)', 'Label' ) );
		$this->assertSame( 'Label', Slack_Message::link( 'ftp://example.org/a', 'Label' ) );
		$this->assertSame( 'Label', Slack_Message::link( '/relative', 'Label' ) );
		$this->assertSame( 'a &amp; b', Slack_Message::link( 'mailto:a@example.org', 'a & b' ) );

		$link = Slack_Message::link( 'https://example.org/a|b>c<d&e f', 'Label' );
		$this->assertSame( 1, preg_match( '/^<([^|<>]*)\|Label>$/', $link, $m ), $link );
		$this->assertStringNotContainsString( ' ', $m[1] );
		$this->assertStringContainsString( '%7C', $m[1] );
		$this->assertStringContainsString( '&amp;e', $m[1] );
	}

	/**
	 * Names, versions, notes and the site are escaped wherever they appear, and cannot make a mention or a link.
	 */
	public function test_values_are_escaped() {
		$item    = $this->item(
			array(
				'name'            => 'Tom & <Jerry> <!channel>',
				'current_version' => '1.0 <b>',
				'new_version'     => '2.0 & more',
				'links'           => array( 'details' => 'https://example.org/p|q' ),
				'notes'           => array( 'Needs <@U123|admin> & more' ),
			)
		);
		$message = Slack_Message::build( $this->notification_of( array( $item ), array(), 'Fish & <Chips>' ) );

		$this->assertSame( 'There are updates available for Fish &amp; &lt;Chips&gt;', $message['blocks'][0]['text']['text'] );
		$text = $this->section_texts( $message )[0];
		$this->assertStringContainsString( '*Tom &amp; &lt;Jerry&gt; &lt;!channel&gt;* from version 1.0 &lt;b&gt; to 2.0 &amp; more', $text );
		$this->assertStringContainsString( '<https://example.org/p%7Cq|Tom &amp; &lt;Jerry&gt; &lt;!channel&gt; details>', $text );
		$this->assertStringContainsString( 'Needs &lt;@U123|admin&gt; &amp; more', $text );
		$closing = end( $message['blocks'] );
		$this->assertStringEndsWith( '|Fish &amp; &lt;Chips&gt;>', $closing['elements'][0]['text'] );
		$this->assertStringContainsString( 'Fish &amp; &lt;Chips&gt;: Tom &amp; &lt;Jerry&gt; &lt;!channel&gt; 2.0 &amp; more', $message['text'] );

		// The only < and > of the section are those of our own link: none comes from the values.
		$this->assertSame( 1, substr_count( $text, '<' ) );
		$this->assertSame( 1, substr_count( $text, '>' ) );
	}

	/**
	 * A site name comes from WordPress with HTML entities, and is shown as the text it stands for.
	 */
	public function test_the_site_name_is_decoded() {
		$message = Slack_Message::build( $this->notification( 1, 'Plugin ', array(), 'Tom &amp; Jerry&#039;s' ) );

		$this->assertSame( 'There are updates available for Tom &amp; Jerry\'s', $message['blocks'][0]['text']['text'] );
	}

	// Limits.

	/**
	 * Sixty items: as many as the limit allows are listed, and the rest is counted.
	 */
	public function test_a_long_list_is_summarised() {
		$message = Slack_Message::build( $this->notification( 60 ) );

		$this->assert_within_limits( $message );
		$listed = substr_count( implode( "\n", $this->section_texts( $message ) ), ' from version 1.0 to 2.0' );
		$this->assertSame( Slack_Message::MAX_ITEMS, $listed );
		$more = $this->section_texts( $message );
		$more = end( $more );
		$this->assertStringStartsWith( '…and 10 more', $more );
		$this->assertStringContainsString( '<https://example.org/wp-admin/update-core.php|', $more );
		$this->assertStringContainsString( '*Plugin 50*', implode( "\n", $this->section_texts( $message ) ) );
		$this->assertStringNotContainsString( '*Plugin 51*', implode( "\n", $this->section_texts( $message ) ) );
	}

	/**
	 * Fifty items fit and are not summarised.
	 */
	public function test_a_list_at_the_limit_is_not_summarised() {
		$message = Slack_Message::build( $this->notification( Slack_Message::MAX_ITEMS ) );

		$this->assert_within_limits( $message );
		$this->assertStringNotContainsString( 'more', implode( "\n", $this->section_texts( $message ) ) );
	}

	/**
	 * Very long names, notes and addresses are cut, so no section is too long and the whole list is still there or counted.
	 */
	public function test_very_long_values_stay_within_the_limits() {
		$items = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$items[] = $this->item(
				array(
					'key'             => 'plugin-' . $i . '/plugin-' . $i . '.php',
					'name'            => $i . str_repeat( ' Long & <name>', 400 ),
					'current_version' => str_repeat( '1.', 300 ),
					'new_version'     => str_repeat( '2.', 300 ),
					'links'           => array(
						'details'   => 'https://example.org/' . str_repeat( 'a', 3000 ),
						'changelog' => 'https://example.org/changelog',
					),
					'notes'           => array_fill( 0, 20, str_repeat( 'Note & <text> ', 100 ) ),
				)
			);
		}
		$message = Slack_Message::build( $this->notification_of( $items, array(), str_repeat( 'Site & <name> ', 400 ) ) );

		$this->assert_within_limits( $message );
		$text = implode( "\n", $this->section_texts( $message ) );
		$this->assertStringNotContainsString( 'https://example.org/' . str_repeat( 'a', 1500 ), $text, 'An address that long is not made a link.' );
		$this->assertStringContainsString( '/changelog|', $text );
		preg_match( '/…and (\d+) more/', $text, $m );
		$this->assertSame( 60, substr_count( $text, ' from version ' ) + (int) $m[1], 'Every item is either listed or counted.' );
		$this->assertStringNotContainsString( '&amp;amp;', $text );
	}

	/**
	 * With many types, each section is one block: the blocks are the limit, and the count of what is left out is right.
	 */
	public function test_many_types_are_limited_by_the_blocks() {
		$types = array();
		$items = array();
		for ( $i = 1; $i <= 55; $i++ ) {
			$types[ 'kind_' . $i ] = 'Kind ' . $i;
			$items[]               = $this->item(
				array(
					'type' => 'kind_' . $i,
					'key'  => 'k' . $i,
					'name' => 'Item ' . $i,
				)
			);
		}
		add_filter(
			'sc_wpun_item_types',
			static function ( $registered ) use ( $types ) {
				return array_merge( $registered, $types );
			}
		);

		$message = Slack_Message::build( $this->notification_of( $items ) );

		$this->assertCount( 50, $message['blocks'], 'The header, 47 types, the line about the rest and the closing block.' );
		$this->assert_within_limits( $message );
		$texts = $this->section_texts( $message );
		$this->assertStringStartsWith( '…and 8 more', end( $texts ) );
		$this->assertStringContainsString( '*Kind 47*', implode( "\n", $texts ) );
		$this->assertStringNotContainsString( '*Kind 48*', implode( "\n", $texts ) );
	}

	/**
	 * Many items of one type continue in the next section.
	 */
	public function test_a_long_type_continues_in_the_next_section() {
		$message = Slack_Message::build( $this->notification( 40, str_repeat( 'Name ', 18 ) ) );

		$texts = $this->section_texts( $message );
		$this->assertGreaterThan( 1, count( $texts ) );
		$this->assertStringStartsWith( '*Plugins*', $texts[0] );
		$this->assertStringStartsWith( '*Name', $texts[1], 'No heading again.' );
		$this->assert_within_limits( $message );
		$this->assertStringNotContainsString( 'more', implode( "\n", $texts ) );
		$this->assertSame( 40, substr_count( implode( "\n", $texts ), ' from version 1.0 to 2.0' ) );
	}

	/**
	 * The filter `sc_wpun_more_items_text` changes the line about what was left out.
	 */
	public function test_the_more_line_filter() {
		add_filter(
			'sc_wpun_more_items_text',
			static function ( $text, $hidden ) {
				return sprintf( '(%d <not> shown)', $hidden );
			},
			10,
			2
		);

		$texts = $this->section_texts( Slack_Message::build( $this->notification( 53 ) ) );

		$this->assertStringStartsWith( '(3 &lt;not&gt; shown)', end( $texts ) );
	}

	/**
	 * Cutting never splits an entity and the result fits.
	 */
	public function test_escape_to_fit() {
		$this->assertSame( 'short &amp;', Slack_Message::escape_to_fit( 'short &', 20 ) );
		foreach ( array( 5, 6, 7, 8, 9, 10, 11 ) as $max ) {
			$fit = Slack_Message::escape_to_fit( '&&&&&&&&&&', $max );
			$this->assertLessThanOrEqual( $max, mb_strlen( $fit ), $max );
			$this->assertSame( 1, preg_match( '/^(&amp;)*…$/', $fit ), $fit );
		}
		$this->assertSame( '…', Slack_Message::escape_to_fit( '&&&&', 3 ) );
	}
}
