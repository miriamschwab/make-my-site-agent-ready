<?php
/**
 * Who runs this site and how to reach them, for the MCP server's get_about tool and its card.
 *
 * Nothing here is new information. It is what the site already publishes about its owner, read from
 * the places WordPress sites keep it:
 *
 * | Site set up as                 | Name, bio, profiles from                                       |
 * |--------------------------------|----------------------------------------------------------------|
 * | a person in Yoast SEO          | that user: display name, biographical info, the profile's       |
 * |                                | contact fields that hold a URL (X's username becomes a link)    |
 * | an organization in Yoast SEO   | Yoast's company name and its organization social profiles      |
 * | neither (no Yoast, or unset)   | the site title and tagline only                                |
 *
 * The person case reads exactly the fields Yoast already puts into the page's Person schema, so the
 * card can never say more about someone than their own homepage already tells search engines. Any
 * contact field a theme or plugin adds to the user profile (`user_contactmethods`) is picked up too,
 * which is how a site adds a network WordPress and Yoast have no field for.
 *
 * `mmsar_about` lets a theme add or override anything (a role line, a contact page, a profile), and
 * whatever comes back from it is checked again: only http(s) links survive, and every string is capped.
 *
 * The rules (profiles(), build(), sanitize(), to_text()) take plain arrays and are tested without
 * WordPress; collect() is the thin layer that reads WordPress.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * About this site, for get_about.
 */
class MMSAR_About {

	/**
	 * Longest bio kept, in characters.
	 */
	const MAX_BIO = 1200;

	/**
	 * Longest short field (name, role, label) kept, in characters.
	 */
	const MAX_FIELD = 160;

	/**
	 * Most profile links kept.
	 */
	const MAX_PROFILES = 12;

	/**
	 * Largest photo built into the card, in bytes, before encoding.
	 *
	 * The photo travels inside the card template, which a host fetches once and may cache, rather
	 * than in every tool result. A WordPress thumbnail is 10–30 KB; anything past this is not a
	 * thumbnail and is left out rather than shipped.
	 */
	const MAX_PHOTO_BYTES = 80000;

	/**
	 * Display names for the contact fields WordPress, Yoast and common themes add. Anything else is
	 * labelled by its link's host name.
	 */
	const LABELS = array(
		'twitter'    => 'X',
		'x'          => 'X',
		'linkedin'   => 'LinkedIn',
		'github'     => 'GitHub',
		'youtube'    => 'YouTube',
		'facebook'   => 'Facebook',
		'instagram'  => 'Instagram',
		'mastodon'   => 'Mastodon',
		'bluesky'    => 'Bluesky',
		'threads'    => 'Threads',
		'wikipedia'  => 'Wikipedia',
		'soundcloud' => 'SoundCloud',
		'tumblr'     => 'Tumblr',
		'pinterest'  => 'Pinterest',
		'myspace'    => 'Myspace',
	);

	/**
	 * Whether a value is an absolute http(s) URL.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_http_url( $value ) {
		if ( ! is_string( $value ) || '' === $value || preg_match( '/\s/', $value ) ) {
			return false;
		}
		$parts = wp_parse_url( $value );
		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			&& '' !== $parts['host'];
	}

	/**
	 * Profile links from a set of contact fields.
	 *
	 * Keeps only values that are links, turns an X username (how Yoast stores it) into a link,
	 * de-duplicates by link, and labels each by its field or, failing that, its host.
	 *
	 * @param array $fields Field key => stored value.
	 * @return array[] Each `array( 'label' => string, 'url' => string )`.
	 */
	public static function profiles( $fields ) {
		$out  = array();
		$seen = array();
		foreach ( $fields as $key => $value ) {
			$key   = strtolower( (string) $key );
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( '' === $value ) {
				continue;
			}
			if ( in_array( $key, array( 'twitter', 'x' ), true ) && ! self::is_http_url( $value ) ) {
				$handle = ltrim( $value, '@' );
				if ( ! preg_match( '/^[A-Za-z0-9_]{1,15}$/', $handle ) ) {
					continue;
				}
				$value = 'https://x.com/' . $handle;
			}
			if ( ! self::is_http_url( $value ) ) {
				continue;
			}
			$dedupe = strtolower( rtrim( $value, '/' ) );
			if ( isset( $seen[ $dedupe ] ) ) {
				continue;
			}
			$seen[ $dedupe ] = true;
			$out[]           = array(
				'label' => self::label_for( $key, $value ),
				'url'   => $value,
			);
			if ( count( $out ) >= self::MAX_PROFILES ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * A profile's label.
	 *
	 * @param string $key Field key, or '' for an unnamed link.
	 * @param string $url The link.
	 * @return string Label.
	 */
	private static function label_for( $key, $url ) {
		if ( isset( self::LABELS[ $key ] ) ) {
			return self::LABELS[ $key ];
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return preg_replace( '/^www\./', '', strtolower( $host ) );
	}

	/**
	 * The about record from raw sources.
	 *
	 * @param array $src {
	 *     Raw values, all optional.
	 *
	 *     @type string $kind        'person', 'organization' or 'site'.
	 *     @type string $site_name   Site title.
	 *     @type string $tagline     Site tagline.
	 *     @type string $website     Home URL.
	 *     @type string $name        Person or organization name.
	 *     @type string $bio         Biographical text.
	 *     @type array  $fields      Contact field key => value (a person's profile).
	 *     @type array  $links       Further profile links, unlabelled (an organization's).
	 *     @type string $contact_url A page for getting in touch.
	 * }
	 * @return array The record, sanitized.
	 */
	public static function build( $src ) {
		$kind = isset( $src['kind'] ) && in_array( $src['kind'], array( 'person', 'organization' ), true ) ? $src['kind'] : 'site';

		$fields = isset( $src['fields'] ) && is_array( $src['fields'] ) ? $src['fields'] : array();
		foreach ( ( isset( $src['links'] ) && is_array( $src['links'] ) ? $src['links'] : array() ) as $i => $link ) {
			$fields[ 'link_' . $i ] = $link;
		}

		$site_name = isset( $src['site_name'] ) ? (string) $src['site_name'] : '';
		$name      = isset( $src['name'] ) ? (string) $src['name'] : '';
		$bio       = isset( $src['bio'] ) ? (string) $src['bio'] : '';
		if ( 'site' === $kind ) {
			$name = $site_name;
			$bio  = isset( $src['tagline'] ) ? (string) $src['tagline'] : '';
		}

		$website  = isset( $src['website'] ) ? (string) $src['website'] : '';
		$profiles = array_values(
			array_filter(
				self::profiles( $fields ),
				static function ( $profile ) use ( $website ) {
					// The site itself is shown as the website, not repeated as a profile.
					return strtolower( rtrim( $profile['url'], '/' ) ) !== strtolower( rtrim( $website, '/' ) );
				}
			)
		);

		return self::sanitize(
			array(
				'kind'        => $kind,
				'name'        => '' !== trim( $name ) ? $name : $site_name,
				'role'        => '',
				'bio'         => $bio,
				'site_name'   => $site_name,
				'website'     => $website,
				'profiles'    => $profiles,
				'contact_url' => isset( $src['contact_url'] ) ? (string) $src['contact_url'] : '',
			)
		);
	}

	/**
	 * Hold a record to the published shape, whoever last touched it.
	 *
	 * Run after `mmsar_about` too, so a filter cannot put a `javascript:` link, a non-string or an
	 * oversized value into a public tool result.
	 *
	 * @param mixed $about Candidate record.
	 * @return array Record with exactly the published keys.
	 */
	public static function sanitize( $about ) {
		$about = is_array( $about ) ? $about : array();
		$text  = static function ( $value, $max ) {
			if ( ! is_string( $value ) ) {
				return '';
			}
			$value = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value ) ) );
			return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
		};
		$url   = static function ( $value ) {
			return self::is_http_url( $value ) ? $value : '';
		};

		$profiles = array();
		foreach ( ( isset( $about['profiles'] ) && is_array( $about['profiles'] ) ? $about['profiles'] : array() ) as $profile ) {
			if ( ! is_array( $profile ) || ! isset( $profile['url'] ) || '' === $url( $profile['url'] ) ) {
				continue;
			}
			$label      = $text( isset( $profile['label'] ) ? $profile['label'] : '', self::MAX_FIELD );
			$profiles[] = array(
				'label' => '' !== $label ? $label : self::label_for( '', $profile['url'] ),
				'url'   => $profile['url'],
			);
			if ( count( $profiles ) >= self::MAX_PROFILES ) {
				break;
			}
		}

		$kind = isset( $about['kind'] ) && in_array( $about['kind'], array( 'person', 'organization', 'site' ), true ) ? $about['kind'] : 'site';

		return array(
			'kind'        => $kind,
			'name'        => $text( isset( $about['name'] ) ? $about['name'] : '', self::MAX_FIELD ),
			'role'        => $text( isset( $about['role'] ) ? $about['role'] : '', self::MAX_FIELD ),
			'bio'         => $text( isset( $about['bio'] ) ? $about['bio'] : '', self::MAX_BIO ),
			'site_name'   => $text( isset( $about['site_name'] ) ? $about['site_name'] : '', self::MAX_FIELD ),
			'website'     => $url( isset( $about['website'] ) ? $about['website'] : '' ),
			'profiles'    => $profiles,
			'contact_url' => $url( isset( $about['contact_url'] ) ? $about['contact_url'] : '' ),
			'contact_api' => $url( isset( $about['contact_api'] ) ? $about['contact_api'] : '' ),
		);
	}

	/**
	 * The record as the text a model reads.
	 *
	 * @param array $about Sanitized record.
	 * @return string Markdown.
	 */
	public static function to_text( $about ) {
		$lines = array( '# ' . ( 'site' === $about['kind'] ? $about['name'] : $about['name'] . ( '' !== $about['site_name'] && $about['site_name'] !== $about['name'] ? ' (' . $about['site_name'] . ')' : '' ) ) );
		if ( '' !== $about['role'] ) {
			$lines[] = $about['role'];
		}
		if ( '' !== $about['bio'] ) {
			$lines[] = '';
			$lines[] = $about['bio'];
		}
		if ( ! empty( $about['profiles'] ) ) {
			$lines[] = '';
			$lines[] = '## Profiles';
			foreach ( $about['profiles'] as $profile ) {
				$lines[] = '- ' . $profile['label'] . ': ' . $profile['url'];
			}
		}
		$lines[] = '';
		$lines[] = '## Getting in touch';
		if ( '' !== $about['contact_url'] ) {
			$lines[] = 'Contact page: ' . $about['contact_url'];
		}
		if ( '' !== $about['website'] ) {
			$lines[] = 'Website: ' . $about['website'];
		}
		if ( '' === $about['contact_url'] ) {
			$lines[] = 'No contact page is published. get_site_overview lists any contact endpoint the site offers.';
		}
		return implode( "\n", $lines );
	}

	// -------------------------------------------------------------------------
	// WordPress layer
	// -------------------------------------------------------------------------

	/**
	 * The record for this site, filtered and checked.
	 *
	 * @return array Sanitized record.
	 */
	public static function get() {
		$about = self::build( self::collect() );

		/**
		 * Filters what get_about publishes about the people or organization behind the site.
		 *
		 * Add a `role` line, a `contact_url`, or entries to `profiles` (each `label` and `url`). The
		 * result is sanitized again afterwards: only http(s) links are kept and strings are capped.
		 * Everything here is public, unauthenticated output.
		 *
		 * @param array $about {
		 *     @type string  $kind        'person', 'organization' or 'site'.
		 *     @type string  $name        Name shown as the card's heading.
		 *     @type string  $role        One line under the name. Empty by default.
		 *     @type string  $bio         Biography or site description.
		 *     @type string  $site_name   Site title.
		 *     @type string  $website     Home URL.
		 *     @type array[] $profiles    Profile links.
		 *     @type string  $contact_url Contact page. Defaults to a published page with the slug `contact`.
		 *     @type string  $contact_api Optional. A contact endpoint on this site's REST API that the
		 *                                about card's form can send to (GET for a `submit_token`, then
		 *                                POST name, email, subject, message). Empty by default: no form.
		 * }
		 */
		return self::sanitize( apply_filters( 'mmsar_about', $about ) );
	}

	/**
	 * Read the raw values from WordPress and, when present, Yoast SEO.
	 *
	 * @return array Sources for build().
	 */
	public static function collect() {
		$src = array(
			'kind'        => 'site',
			'site_name'   => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'tagline'     => html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'website'     => home_url( '/' ),
			'contact_url' => self::default_contact_url(),
		);

		$yoast = defined( 'WPSEO_VERSION' ) ? get_option( 'wpseo_titles', array() ) : array();
		$type  = is_array( $yoast ) && isset( $yoast['company_or_person'] ) ? $yoast['company_or_person'] : '';

		if ( 'person' === $type && ! empty( $yoast['company_or_person_user_id'] ) ) {
			$user = get_userdata( (int) $yoast['company_or_person_user_id'] );
			if ( $user ) {
				$fields = array();
				foreach ( array_keys( wp_get_user_contact_methods( $user ) ) as $key ) {
					$fields[ $key ] = (string) get_user_meta( $user->ID, $key, true );
				}
				// The profile's own Website field comes last, so a network link is listed first.
				$fields['user_url'] = (string) $user->user_url;

				$src['kind']   = 'person';
				$src['name']   = $user->display_name;
				$src['bio']    = (string) get_user_meta( $user->ID, 'description', true );
				$src['fields'] = $fields;
			}
		} elseif ( 'company' === $type && ! empty( $yoast['company_name'] ) ) {
			$social = get_option( 'wpseo_social', array() );
			$social = is_array( $social ) ? $social : array();

			$src['kind']   = 'organization';
			$src['name']   = (string) $yoast['company_name'];
			$src['fields'] = array(
				'facebook' => isset( $social['facebook_site'] ) ? (string) $social['facebook_site'] : '',
				'twitter'  => isset( $social['twitter_site'] ) ? (string) $social['twitter_site'] : '',
			);
			$src['links']  = isset( $social['other_social_urls'] ) && is_array( $social['other_social_urls'] ) ? $social['other_social_urls'] : array();
		}

		return $src;
	}

	/**
	 * A published page with the slug `contact`, the convention most sites follow.
	 *
	 * @return string URL, or ''.
	 */
	private static function default_contact_url() {
		$page = get_page_by_path( 'contact' );
		if ( $page instanceof WP_Post && 'publish' === $page->post_status && '' === $page->post_password ) {
			return (string) get_permalink( $page );
		}
		return '';
	}

	/**
	 * The owner's photo as a data URI, for the card template.
	 *
	 * Yoast's person image or organization logo, else the site icon, at WordPress's thumbnail size.
	 * Read from disk and built into the template, so the card needs no network and the site never
	 * sees who viewed it.
	 *
	 * @return string `data:` URI, or '' when there is no suitable image.
	 */
	public static function photo_data_uri() {
		$yoast = defined( 'WPSEO_VERSION' ) ? get_option( 'wpseo_titles', array() ) : array();
		$id    = 0;
		if ( is_array( $yoast ) && isset( $yoast['company_or_person'] ) ) {
			$key = 'person' === $yoast['company_or_person'] ? 'person_logo_id' : 'company_logo_id';
			$id  = isset( $yoast[ $key ] ) ? (int) $yoast[ $key ] : 0;
		}
		if ( ! $id ) {
			$id = (int) get_option( 'site_icon' );
		}

		/**
		 * Filters which attachment get_about's card shows as its photo. Return 0 for none.
		 *
		 * @param int $id Attachment ID.
		 */
		$id = (int) apply_filters( 'mmsar_about_photo_id', $id );
		if ( $id <= 0 ) {
			return '';
		}

		$file = get_attached_file( $id );
		if ( ! $file ) {
			return '';
		}
		$thumb = image_get_intermediate_size( $id, 'thumbnail' );
		if ( is_array( $thumb ) && ! empty( $thumb['path'] ) ) {
			$uploads = wp_get_upload_dir();
			$file    = trailingslashit( $uploads['basedir'] ) . $thumb['path'];
		}

		$type = wp_check_filetype( $file );
		if ( ! in_array( $type['type'], array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) {
			return '';
		}
		if ( ! is_readable( $file ) || filesize( $file ) > self::MAX_PHOTO_BYTES ) {
			return '';
		}
		$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local attachment file, not a URL.
		if ( false === $bytes || '' === $bytes ) {
			return '';
		}
		return 'data:' . $type['type'] . ';base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Builds a data: URI for an image.
	}
}
