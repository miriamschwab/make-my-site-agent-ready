<?php
/**
 * The get_about record (1.68.0).
 *
 * The rules in MMSAR_About are pure, so each is tested on plain arrays: which contact fields become
 * profile links (only links, an X username turned into one, duplicates and the site's own address
 * dropped), how the three kinds of site fill the record, and that sanitize() holds whatever a filter
 * returns to the published shape. The last group is the security half: nothing but an http(s) link
 * may reach a public tool result or the card that renders it.
 *
 * @package Make_My_Site_Agent_Ready
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MMSAR_About rules.
 */
final class AboutTest extends TestCase {

	/**
	 * Which values count as links.
	 *
	 * @return array[]
	 */
	public static function url_cases() {
		return array(
			'https'                => array( 'https://github.com/miriamschwab', true ),
			'http'                 => array( 'http://example.com/', true ),
			'uppercase scheme'     => array( 'HTTPS://example.com', true ),
			'javascript'           => array( 'javascript:alert(1)', false ),
			'data'                 => array( 'data:text/html,hi', false ),
			'protocol-relative'    => array( '//example.com', false ),
			'bare host'            => array( 'example.com', false ),
			'username'             => array( 'miriamschwab', false ),
			'embedded space'       => array( 'https://exa mple.com', false ),
			'not a string'         => array( array( 'https://example.com' ), false ),
			'empty'                => array( '', false ),
			'mailto'               => array( 'mailto:a@b.c', false ),
		);
	}

	/**
	 * Only absolute http(s) URLs are links.
	 *
	 * @param mixed $value    Candidate.
	 * @param bool  $expected Whether it is a link.
	 */
	#[DataProvider( 'url_cases' )]
	public function test_is_http_url( $value, $expected ) {
		$this->assertSame( $expected, MMSAR_About::is_http_url( $value ) );
	}

	/**
	 * A person's profile fields, as WordPress and Yoast store them, become labelled links.
	 */
	public function test_profiles_from_person_fields() {
		$profiles = MMSAR_About::profiles(
			array(
				'facebook' => '',
				'linkedin' => 'https://www.linkedin.com/in/miriamschwab/',
				'twitter'  => 'miriamschwab',
				'github'   => 'https://github.com/miriamschwab',
				'custom'   => 'https://bsky.app/profile/miriamschwab.me',
			)
		);
		$this->assertSame(
			array(
				array(
					'label' => 'LinkedIn',
					'url'   => 'https://www.linkedin.com/in/miriamschwab/',
				),
				array(
					'label' => 'X',
					'url'   => 'https://x.com/miriamschwab',
				),
				array(
					'label' => 'GitHub',
					'url'   => 'https://github.com/miriamschwab',
				),
				array(
					'label' => 'bsky.app',
					'url'   => 'https://bsky.app/profile/miriamschwab.me',
				),
			),
			$profiles
		);
	}

	/**
	 * X usernames in the forms people type them.
	 *
	 * @return array[]
	 */
	public static function x_cases() {
		return array(
			'bare'             => array( 'miriamschwab', 'https://x.com/miriamschwab' ),
			'with at'          => array( '@miriamschwab', 'https://x.com/miriamschwab' ),
			'already a link'   => array( 'https://twitter.com/MiriamSchwab', 'https://twitter.com/MiriamSchwab' ),
			'too long'         => array( 'abcdefghijklmnop', null ),
			'invalid chars'    => array( 'miriam schwab', null ),
			'script attempt'   => array( '"><script>', null ),
		);
	}

	/**
	 * An X username becomes a link only when it is a valid handle.
	 *
	 * @param string      $value    Stored value.
	 * @param string|null $expected Resulting link, or null for none.
	 */
	#[DataProvider( 'x_cases' )]
	public function test_x_username( $value, $expected ) {
		$profiles = MMSAR_About::profiles( array( 'twitter' => $value ) );
		if ( null === $expected ) {
			$this->assertSame( array(), $profiles );
			return;
		}
		$this->assertSame( $expected, $profiles[0]['url'] );
		$this->assertSame( 'X', $profiles[0]['label'] );
	}

	/**
	 * The same link twice is listed once, trailing slash and case aside, and the list is capped.
	 */
	public function test_profiles_dedupe_and_cap() {
		$profiles = MMSAR_About::profiles(
			array(
				'linkedin' => 'https://www.linkedin.com/in/miriamschwab/',
				'other'    => 'https://WWW.linkedin.com/in/miriamschwab',
			)
		);
		$this->assertCount( 1, $profiles );

		$many = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$many[ 'f' . $i ] = 'https://example.com/' . $i;
		}
		$this->assertCount( MMSAR_About::MAX_PROFILES, MMSAR_About::profiles( $many ) );
	}

	/**
	 * A person: their name and bio, the site's title kept alongside, the site's own address not
	 * repeated as a profile.
	 */
	public function test_build_person() {
		$about = MMSAR_About::build(
			array(
				'kind'        => 'person',
				'site_name'   => 'MiriamSchwab.me',
				'tagline'     => 'Posts, talks, and plugins',
				'website'     => 'https://miriamschwab.me/',
				'name'        => 'Miriam Schwab',
				'bio'         => "Miriam is Head of WordPress\n at Elementor.",
				'fields'      => array(
					'linkedin' => 'https://www.linkedin.com/in/miriamschwab/',
					'user_url' => 'https://miriamschwab.me',
				),
				'contact_url' => 'https://miriamschwab.me/contact/',
			)
		);
		$this->assertSame( 'person', $about['kind'] );
		$this->assertSame( 'Miriam Schwab', $about['name'] );
		$this->assertSame( 'Miriam is Head of WordPress at Elementor.', $about['bio'] );
		$this->assertSame( 'MiriamSchwab.me', $about['site_name'] );
		$this->assertSame( '', $about['role'] );
		$this->assertCount( 1, $about['profiles'] );
		$this->assertSame( 'https://miriamschwab.me/contact/', $about['contact_url'] );
	}

	/**
	 * An organization: its name, and its unlabelled profile links labelled by host.
	 */
	public function test_build_organization() {
		$about = MMSAR_About::build(
			array(
				'kind'      => 'organization',
				'site_name' => 'Acme',
				'name'      => 'Acme Inc.',
				'fields'    => array( 'twitter' => 'acme' ),
				'links'     => array( 'https://www.youtube.com/@acme', 'not a link' ),
			)
		);
		$this->assertSame( 'organization', $about['kind'] );
		$this->assertSame( 'Acme Inc.', $about['name'] );
		$this->assertSame( array( 'X', 'youtube.com' ), array_column( $about['profiles'], 'label' ) );
	}

	/**
	 * No identity configured: the site's title and tagline, and nothing about any user.
	 */
	public function test_build_site_fallback_ignores_person_fields() {
		$about = MMSAR_About::build(
			array(
				'site_name' => 'Example',
				'tagline'   => 'A site',
				'name'      => 'Someone',
				'bio'       => 'Private bio',
			)
		);
		$this->assertSame( 'site', $about['kind'] );
		$this->assertSame( 'Example', $about['name'] );
		$this->assertSame( 'A site', $about['bio'] );
	}

	/**
	 * Whatever a filter returns, the published record has exactly these keys and safe values.
	 */
	public function test_sanitize_holds_filter_output() {
		$about = MMSAR_About::sanitize(
			array(
				'kind'        => 'robot',
				'name'        => '<b>Miriam</b>',
				'role'        => str_repeat( 'r', 500 ),
				'bio'         => array( 'not', 'a', 'string' ),
				'website'     => 'javascript:alert(1)',
				'contact_url' => 'https://miriamschwab.me/contact/',
				'profiles'    => array(
					array(
						'label' => 'Evil',
						'url'   => 'javascript:alert(1)',
					),
					'not an array',
					array( 'url' => 'https://github.com/miriamschwab' ),
					array(
						'label' => 'Bluesky',
						'url'   => 'https://bsky.app/profile/x',
					),
				),
				'extra'       => 'dropped',
			)
		);
		$this->assertSame( array( 'kind', 'name', 'role', 'bio', 'site_name', 'website', 'profiles', 'contact_url', 'contact_api' ), array_keys( $about ) );
		$this->assertSame( 'site', $about['kind'] );
		$this->assertSame( 'Miriam', $about['name'] );
		$this->assertSame( MMSAR_About::MAX_FIELD, strlen( $about['role'] ) );
		$this->assertSame( '', $about['bio'] );
		$this->assertSame( '', $about['website'] );
		$this->assertSame(
			array(
				array(
					'label' => 'github.com',
					'url'   => 'https://github.com/miriamschwab',
				),
				array(
					'label' => 'Bluesky',
					'url'   => 'https://bsky.app/profile/x',
				),
			),
			$about['profiles']
		);
	}

	/**
	 * The text a model reads names the person, lists profiles and says how to get in touch.
	 */
	public function test_to_text() {
		$text = MMSAR_About::to_text(
			MMSAR_About::build(
				array(
					'kind'        => 'person',
					'site_name'   => 'MiriamSchwab.me',
					'website'     => 'https://miriamschwab.me/',
					'name'        => 'Miriam Schwab',
					'bio'         => 'Head of WordPress at Elementor.',
					'fields'      => array( 'twitter' => 'miriamschwab' ),
					'contact_url' => 'https://miriamschwab.me/contact/',
				)
			)
		);
		$this->assertStringStartsWith( "# Miriam Schwab (MiriamSchwab.me)\n", $text );
		$this->assertStringContainsString( '- X: https://x.com/miriamschwab', $text );
		$this->assertStringContainsString( 'Contact page: https://miriamschwab.me/contact/', $text );
		$this->assertStringNotContainsString( 'No contact page', $text );

		$bare = MMSAR_About::to_text( MMSAR_About::build( array( 'site_name' => 'Example' ) ) );
		$this->assertStringContainsString( 'No contact page is published.', $bare );
	}
}
