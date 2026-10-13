<?php
/**
 * Endpoint registry — lets other plugins and themes list an endpoint of their own in the
 * agent-facing documents this plugin publishes: /.well-known/api-catalog, /llms.txt, and the
 * Agent Skills index at /.well-known/agent-skills/index.json.
 *
 * An endpoint is described once, in one array, and fanned out to every surface it opts into.
 * The alternative — a separate hook per document — would make an integration describe the same
 * endpoint three times in three shapes and let the descriptions drift apart.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMSAR endpoint registry.
 */
class MMSAR_Registry {

	/**
	 * The documents an endpoint can be listed in. An endpoint that opts into none of them is
	 * dropped, since there is nowhere left to publish it.
	 */
	const SURFACES = array( 'api_catalog', 'llms_txt', 'agent_skills' );

	/**
	 * Link relations accepted for the api-catalog linkset, from the IANA link relations registry.
	 * Anything outside this list falls back to `item`: a linkset is only useful to an agent if the
	 * relation actually means what the agent thinks it means, so an unrecognized one is not passed
	 * through to the published document.
	 */
	const RELATIONS = array( 'item', 'service-desc', 'service-doc', 'describedby', 'status', 'terms-of-service', 'license' );

	/**
	 * HTTP methods that may be documented for an endpoint.
	 */
	const METHODS = array( 'GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' );

	/**
	 * Most example questions kept for one endpoint. ARD asks for two to five; anything past five is
	 * dropped rather than published, since a registry may reject a longer list.
	 */
	const MAX_QUERIES = 5;

	/**
	 * Option holding the endpoints the site owner added on the settings page.
	 */
	const OPTION = 'mmsar_endpoints';

	/**
	 * Endpoints added imperatively via mmsar_register_endpoint().
	 *
	 * @var array[]
	 */
	private static $registered = array();

	/**
	 * The endpoints entered on the settings page, exactly as stored.
	 *
	 * Returned unvalidated and in storage order, because the settings screen has to be able to show
	 * a row that is not currently publishable — otherwise a typo in a URL would make the row vanish
	 * from the page with no way to correct it.
	 *
	 * @return array[] Stored endpoint rows.
	 */
	public static function get_stored() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? array_values( $stored ) : array();
	}

	/**
	 * Registers an endpoint for inclusion in this site's agent-facing documents.
	 *
	 * Call on `init` or earlier — the documents are built on `template_redirect`.
	 *
	 * @param array $endpoint Endpoint descriptor. See normalize() for the accepted keys.
	 * @return bool True if the descriptor is valid and was stored, false if it was rejected.
	 */
	public static function register( $endpoint ) {
		if ( null === self::normalize( $endpoint ) ) {
			return false;
		}
		self::$registered[] = $endpoint;
		return true;
	}

	/**
	 * Every registered endpoint, validated and normalized.
	 *
	 * Deliberately not memoised: registration can legitimately happen at any point before the
	 * document is rendered, and a cached empty list from an early call would silently drop a
	 * late registration.
	 *
	 * @return array[] Normalized endpoint descriptors, keyed numerically.
	 */
	public static function get_endpoints() {
		/**
		 * Filters the endpoints listed in this site's agent-facing documents.
		 *
		 * Each entry is an array describing one endpoint:
		 *
		 *   id          string   Optional. Stable slug, used as the Agent Skills entry name.
		 *                        Derived from the title when omitted.
		 *   title       string   Required. Short human-readable name.
		 *   href        string   Required. Absolute http(s) URL of the endpoint.
		 *   description string   Optional. One sentence on what the endpoint does and when to use it.
		 *   type        string   Optional but recommended. The media type the endpoint actually
		 *                        returns, e.g. 'application/json'. Omitted when not supplied —
		 *                        a guessed type misrepresents the endpoint to an agent.
		 *   rel         string   Optional. api-catalog link relation, one of self::RELATIONS.
		 *                        Default 'item'. Use 'service-desc' for a machine-readable API
		 *                        description, 'service-doc' for human documentation.
		 *   methods     string[] Optional. HTTP methods the endpoint accepts, e.g. array( 'POST' ).
		 *   auth        string   Optional. How to authenticate, e.g. 'none' or 'X-Api-Key header'.
		 *   surfaces    string[] Optional. Which documents to appear in, from self::SURFACES.
		 *                        Defaults to all three.
		 *   skill_url   string   Optional. Absolute URL of a SKILL.md the integration serves itself.
		 *                        When set, the endpoint gets its own entry in the Agent Skills index
		 *                        instead of a bullet inside this plugin's SKILL.md.
		 *   skill_digest string  Optional. 'sha256:<hex>' digest of that SKILL.md.
		 *   queries     string[] Optional. Two to five questions a person might ask that this
		 *                        endpoint answers, e.g. 'send a message to the site owner'. Used only
		 *                        as `representativeQueries` in /.well-known/ai-catalog.json, which is
		 *                        what an ARD registry indexes for search. Up to five are kept.
		 *
		 * Invalid entries are dropped rather than published — these documents are read by agents,
		 * so a malformed entry is worse than a missing one.
		 *
		 * Endpoints added on the settings page come first, so a plugin filtering this list can see
		 * and adjust what the site owner entered.
		 *
		 * @param array[] $endpoints Endpoint descriptors registered so far.
		 */
		$raw = apply_filters( 'mmsar_registered_endpoints', array_merge( self::get_stored(), self::$registered ) );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$endpoints = array();
		$seen      = array();
		foreach ( $raw as $candidate ) {
			$endpoint = self::normalize( $candidate );
			if ( null === $endpoint ) {
				continue;
			}
			// First registration of an id wins, so a plugin registering on both a direct call and
			// the filter (or on a hook that fires twice) lists its endpoint once, not twice.
			if ( isset( $seen[ $endpoint['id'] ] ) ) {
				continue;
			}
			$seen[ $endpoint['id'] ] = true;
			$endpoints[]             = $endpoint;
		}

		return $endpoints;
	}

	/**
	 * The registered endpoints that opted into one particular document.
	 *
	 * @param string $surface One of self::SURFACES.
	 * @return array[] Normalized endpoint descriptors.
	 */
	public static function get_for_surface( $surface ) {
		$matched = array();
		foreach ( self::get_endpoints() as $endpoint ) {
			if ( in_array( $surface, $endpoint['surfaces'], true ) ) {
				$matched[] = $endpoint;
			}
		}
		return $matched;
	}

	/**
	 * Cleans the rows submitted by the settings screen for storage.
	 *
	 * Deliberately more forgiving than normalize(): storing a row and publishing it are different
	 * questions. A row with a mistyped URL is kept so the owner can see and fix it on the next page
	 * load — normalize() is what decides, separately, whether it is fit to appear in a document.
	 * Sanitizing to the point of blanking the field would delete the evidence of the mistake.
	 *
	 * @param mixed $rows Raw $_POST rows.
	 * @return array[] Rows fit to store.
	 */
	public static function sanitize_rows( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$clean = array();
		$ids   = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
				continue;
			}

			$title = isset( $row['title'] ) ? self::one_line( $row['title'], 120 ) : '';
			$href  = isset( $row['href'] ) ? self::one_line( $row['href'], 500 ) : '';

			// The blank row at the bottom of the form, left untouched.
			if ( '' === $title && '' === $href ) {
				continue;
			}

			// Typing a bare domain is the common case and browsers have trained everyone to expect
			// it to work, so supply the scheme rather than failing validation over it. Only for
			// values that could plausibly be a URL: prefixing a sentence turns obvious nonsense into
			// something that survives URL escaping (spaces become %20) and looks publishable.
			if ( '' !== $href && ! preg_match( '#\s#', $href ) && ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $href ) ) {
				$href = 'https://' . ltrim( $href, '/' );
			}

			// The settings form submits a comma-separated string; the Abilities API submits an array.
			// Both arrive here, so accept either rather than making one caller reshape for the other.
			$methods = array();
			if ( isset( $row['methods'] ) ) {
				$raw_methods = is_array( $row['methods'] ) ? implode( ',', $row['methods'] ) : (string) $row['methods'];
				foreach ( preg_split( '/[\s,]+/', $raw_methods ) as $method ) {
					$method = strtoupper( trim( $method ) );
					if ( in_array( $method, self::METHODS, true ) && ! in_array( $method, $methods, true ) ) {
						$methods[] = $method;
					}
				}
			}

			$rel = isset( $row['rel'] ) ? strtolower( trim( (string) $row['rel'] ) ) : 'item';
			if ( ! in_array( $rel, self::RELATIONS, true ) ) {
				$rel = 'item';
			}

			$surfaces = isset( $row['surfaces'] ) && is_array( $row['surfaces'] )
				? array_values( array_intersect( self::SURFACES, $row['surfaces'] ) )
				: array();

			// Keep the id stable across edits: an id already assigned is reused even if the title is
			// later reworded, so the Agent Skills entry name an agent may have cached does not move.
			$id = isset( $row['id'] ) ? sanitize_key( $row['id'] ) : '';
			if ( '' === $id ) {
				// sanitize_key alone would strip the spaces out entirely ("Booking API" -> "bookingapi").
				// sanitize_title turns them into dashes first, which reads better as an Agent Skills name.
				$id = sanitize_key( sanitize_title( $title ) );
			}
			if ( '' === $id ) {
				$id = 'endpoint';
			}
			$base = $id;
			$n    = 2;
			while ( in_array( $id, $ids, true ) ) {
				$id = $base . '-' . $n;
				++$n;
			}
			$ids[] = $id;

			$clean[] = array(
				'id'          => $id,
				'title'       => $title,
				'href'        => $href,
				'description' => isset( $row['description'] ) ? self::one_line( $row['description'], 300 ) : '',
				'type'        => isset( $row['type'] ) ? self::one_line( $row['type'], 100 ) : '',
				'rel'         => $rel,
				'methods'     => $methods,
				'auth'        => isset( $row['auth'] ) ? self::one_line( $row['auth'], 120 ) : '',
				'surfaces'    => $surfaces,
				'queries'     => isset( $row['queries'] ) ? self::queries( $row['queries'] ) : array(),
			);
		}

		return $clean;
	}

	/**
	 * Cleans a list of example questions.
	 *
	 * The settings form submits one question per line in a textarea; the Abilities API and code
	 * registration submit an array. Both arrive here. Each question is flattened to one line,
	 * empty and repeated ones are dropped (case-insensitively), and at most MAX_QUERIES are kept.
	 *
	 * @param mixed $raw Array of strings, or a newline-separated string.
	 * @return string[] Clean questions, in the order given.
	 */
	public static function queries( $raw ) {
		$items = is_array( $raw ) ? $raw : preg_split( '/\R/u', (string) $raw );
		$clean = array();
		$seen  = array();
		foreach ( (array) $items as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$query = self::one_line( $item, 200 );
			$key   = mb_strtolower( $query );
			if ( '' === $query || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$clean[]      = $query;
			if ( count( $clean ) >= self::MAX_QUERIES ) {
				break;
			}
		}
		return $clean;
	}

	// -------------------------------------------------------------------------
	// Validation
	// -------------------------------------------------------------------------

	/**
	 * Validates and normalizes one endpoint descriptor.
	 *
	 * Everything here arrives from third-party code and is published verbatim into documents
	 * agents act on, so each field is constrained to a shape that cannot break the document it
	 * lands in — no line breaks in a line-oriented file, no non-http schemes in a link target,
	 * no invented media types.
	 *
	 * Public so the settings screen can ask "would this row actually be published?" and say so on
	 * the row itself, rather than letting a mistyped URL silently drop the entry.
	 *
	 * @param mixed $endpoint Raw descriptor.
	 * @return array|null Normalized descriptor, or null if it cannot be published safely.
	 */
	public static function normalize( $endpoint ) {
		if ( ! is_array( $endpoint ) ) {
			return null;
		}

		// Only http(s) targets. A relative path cannot be resolved reliably by an agent reading a
		// document out of context, and a javascript:/data: URI has no business in a link target.
		$href = isset( $endpoint['href'] ) ? esc_url_raw( trim( (string) $endpoint['href'] ), array( 'http', 'https' ) ) : '';
		if ( '' === $href ) {
			return null;
		}

		// esc_url_raw is an escaper, not a validator: it percent-encodes its way out of trouble, so
		// a typo like "https://not a url" survives it as "https://not%20a%20url" and would be
		// published as a real link. Require a host that actually looks like one.
		//
		// Unicode letters are allowed so a site on an internationalized domain is not shut out;
		// the characters that matter to reject here are the ones a mistyped value leaves behind
		// (%, spaces) rather than anything outside ASCII. Invalid UTF-8 fails the match and is
		// rejected, which is the right default.
		$host = wp_parse_url( $href, PHP_URL_HOST );
		if ( ! $host || ! preg_match( '/^[\p{L}\p{N}]([\p{L}\p{N}-]*[\p{L}\p{N}])?(\.[\p{L}\p{N}]([\p{L}\p{N}-]*[\p{L}\p{N}])?)*$/u', $host ) ) {
			return null;
		}

		$title = isset( $endpoint['title'] ) ? self::one_line( $endpoint['title'], 120 ) : '';
		if ( '' === $title ) {
			return null;
		}

		$id = isset( $endpoint['id'] ) ? sanitize_key( $endpoint['id'] ) : '';
		if ( '' === $id ) {
			$id = sanitize_key( sanitize_title( $title ) );
		}
		if ( '' === $id ) {
			// A title made entirely of characters sanitize_key strips (e.g. non-Latin) still needs a
			// stable id, and the href is the one field guaranteed to be present and unique-ish.
			$id = 'endpoint-' . substr( md5( $href ), 0, 8 );
		}

		$surfaces = isset( $endpoint['surfaces'] ) && is_array( $endpoint['surfaces'] )
			? array_values( array_intersect( self::SURFACES, $endpoint['surfaces'] ) )
			: self::SURFACES;
		if ( empty( $surfaces ) ) {
			return null;
		}

		$rel = isset( $endpoint['rel'] ) ? strtolower( trim( (string) $endpoint['rel'] ) ) : 'item';
		if ( ! in_array( $rel, self::RELATIONS, true ) ) {
			$rel = 'item';
		}

		$methods = array();
		if ( isset( $endpoint['methods'] ) ) {
			$candidates = is_array( $endpoint['methods'] ) ? $endpoint['methods'] : array( $endpoint['methods'] );
			foreach ( $candidates as $method ) {
				$method = strtoupper( trim( (string) $method ) );
				if ( in_array( $method, self::METHODS, true ) && ! in_array( $method, $methods, true ) ) {
					$methods[] = $method;
				}
			}
		}

		$skill_url    = isset( $endpoint['skill_url'] ) ? esc_url_raw( trim( (string) $endpoint['skill_url'] ), array( 'http', 'https' ) ) : '';
		$skill_digest = isset( $endpoint['skill_digest'] ) ? strtolower( trim( (string) $endpoint['skill_digest'] ) ) : '';
		if ( ! preg_match( '/^sha256:[a-f0-9]{64}$/', $skill_digest ) ) {
			$skill_digest = '';
		}

		return array(
			'id'           => $id,
			'title'        => $title,
			'href'         => $href,
			'description'  => isset( $endpoint['description'] ) ? self::one_line( $endpoint['description'], 300 ) : '',
			'type'         => isset( $endpoint['type'] ) ? self::media_type( $endpoint['type'] ) : '',
			'rel'          => $rel,
			'methods'      => $methods,
			'auth'         => isset( $endpoint['auth'] ) ? self::one_line( $endpoint['auth'], 120 ) : '',
			'surfaces'     => $surfaces,
			'skill_url'    => $skill_url,
			'skill_digest' => $skill_digest,
			'queries'      => isset( $endpoint['queries'] ) ? self::queries( $endpoint['queries'] ) : array(),
		);
	}

	/**
	 * Flattens text to a single trimmed line of plain text, capped in length.
	 *
	 * Both llms.txt and SKILL.md are line-oriented: an embedded newline in a description would end
	 * the bullet early and let the remainder pose as a new list item or heading.
	 *
	 * @param mixed $text  Raw text.
	 * @param int   $limit Maximum length in characters.
	 * @return string Single-line plain text.
	 */
	private static function one_line( $text, $limit ) {
		$text = sanitize_text_field( (string) $text );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		return mb_substr( $text, 0, $limit );
	}

	/**
	 * Escapes text destined for a markdown document so it reads as the literal text it is.
	 *
	 * Flattening to one line already stops a value ending its line, which is what would let it pose
	 * as a new bullet or heading. This handles what is left within a line: link syntax, which could smuggle
	 * a hyperlink to somewhere else entirely into a description, and backticks, which could close
	 * the code span an endpoint URL sits inside. Registrations come from plugin code, but that code
	 * may well be passing along a label a site visitor supplied.
	 *
	 * @param string $text Single-line plain text.
	 * @return string Text safe to place inside a markdown line.
	 */
	private static function md_text( $text ) {
		return str_replace( array( '[', ']', '`' ), array( '\[', '\]', '\`' ), $text );
	}

	/**
	 * Validates a media type, per the RFC 9110 grammar for type/subtype.
	 *
	 * Returns '' rather than a default for anything unrecognized: the api-catalog and SKILL.md
	 * both promise that a stated type is the type the endpoint really returns, so a guessed
	 * `application/json` on an endpoint that serves XML is worse than saying nothing.
	 *
	 * @param mixed $type Raw media type.
	 * @return string Validated media type, or '' if it is not one.
	 */
	private static function media_type( $type ) {
		$type = strtolower( trim( (string) $type ) );
		if ( ! preg_match( '#^[a-z0-9][a-z0-9!\#$&^_.+-]*/[a-z0-9][a-z0-9!\#$&^_.+-]*$#', $type ) ) {
			return '';
		}
		return $type;
	}

	// -------------------------------------------------------------------------
	// Surface renderers
	// -------------------------------------------------------------------------

	/**
	 * Registered endpoints as api-catalog link objects, grouped by link relation so the caller can
	 * merge each group into the linkset entry alongside the plugin's own links of that relation.
	 *
	 * @return array<string, array[]> Map of relation => list of linkset link objects.
	 */
	public static function api_catalog_links() {
		$grouped = array();
		foreach ( self::get_for_surface( 'api_catalog' ) as $endpoint ) {
			$link = array( 'href' => $endpoint['href'] );
			if ( '' !== $endpoint['type'] ) {
				$link['type'] = $endpoint['type'];
			}
			// `title` is a target attribute in the linkset JSON serialisation (RFC 9264 §4.2).
			$link['title'] = $endpoint['title'];

			$grouped[ $endpoint['rel'] ][] = $link;
		}
		return $grouped;
	}

	/**
	 * The llms.txt section listing registered endpoints, or '' when nothing is registered.
	 *
	 * @return string Markdown section, with a leading blank line, or ''.
	 */
	public static function llms_txt_section() {
		$endpoints = self::get_for_surface( 'llms_txt' );
		if ( empty( $endpoints ) ) {
			return '';
		}

		$lines = array( '', '## Agent Endpoints', '' );
		foreach ( $endpoints as $endpoint ) {
			$line = '- [' . self::md_text( $endpoint['title'] ) . '](' . $endpoint['href'] . ')';
			if ( '' !== $endpoint['description'] ) {
				$line .= ': ' . self::md_text( $endpoint['description'] );
			}
			$facts = self::technical_facts( $endpoint );
			if ( '' !== $facts ) {
				$line .= ' (' . $facts . ')';
			}
			$lines[] = $line;
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The SKILL.md section describing endpoints an agent can call, or '' when none are registered.
	 *
	 * Endpoints that ship their own SKILL.md are left out — they appear as their own entry in the
	 * Agent Skills index instead, and documenting them twice invites the two copies to disagree.
	 *
	 * @return string Markdown section, with a leading blank line, or ''.
	 */
	public static function skill_md_section() {
		$endpoints = array();
		foreach ( self::get_for_surface( 'agent_skills' ) as $endpoint ) {
			if ( '' === $endpoint['skill_url'] ) {
				$endpoints[] = $endpoint;
			}
		}
		if ( empty( $endpoints ) ) {
			return '';
		}

		$lines = array(
			'',
			'## Actions',
			'',
			'Beyond reading content, this site exposes endpoints you can call directly:',
			'',
		);
		foreach ( $endpoints as $endpoint ) {
			$method = $endpoint['methods'] ? implode( '/', $endpoint['methods'] ) . ' ' : '';
			$line   = '- **' . self::md_text( $endpoint['title'] ) . '** — `' . $method . $endpoint['href'] . '`';
			if ( '' !== $endpoint['type'] ) {
				$line .= ' (' . $endpoint['type'] . ')';
			}
			if ( '' !== $endpoint['description'] ) {
				$line .= '. ' . self::md_text( rtrim( $endpoint['description'], '.' ) ) . '.';
			} else {
				$line .= '.';
			}
			if ( '' !== $endpoint['auth'] ) {
				$line .= ' Auth: ' . self::md_text( rtrim( $endpoint['auth'], '.' ) ) . '.';
			}
			$lines[] = $line;
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Registered endpoints that serve their own SKILL.md, as Agent Skills index entries.
	 *
	 * @return array[] Skill entries for the index's `skills` array.
	 */
	public static function agent_skill_entries() {
		$entries = array();
		foreach ( self::get_for_surface( 'agent_skills' ) as $endpoint ) {
			if ( '' === $endpoint['skill_url'] ) {
				continue;
			}
			$entry = array(
				'name'        => $endpoint['id'],
				'type'        => 'skill-md',
				'description' => '' !== $endpoint['description'] ? $endpoint['description'] : $endpoint['title'],
				'url'         => $endpoint['skill_url'],
			);
			// The digest is what lets an agent cache a skill and detect when it changed. We cannot
			// compute one for a file another plugin serves, so it is included only when supplied.
			if ( '' !== $endpoint['skill_digest'] ) {
				$entry['digest'] = $endpoint['skill_digest'];
			}
			$entries[] = $entry;
		}
		return $entries;
	}

	/**
	 * The parenthetical technical summary for an llms.txt bullet — methods, media type, auth.
	 *
	 * @param array $endpoint Normalized endpoint descriptor.
	 * @return string Summary such as "POST · application/json · no auth", or ''.
	 */
	private static function technical_facts( $endpoint ) {
		$facts = array();
		if ( $endpoint['methods'] ) {
			$facts[] = implode( '/', $endpoint['methods'] );
		}
		if ( '' !== $endpoint['type'] ) {
			$facts[] = $endpoint['type'];
		}
		if ( '' !== $endpoint['auth'] ) {
			$facts[] = 'auth: ' . $endpoint['auth'];
		}
		return implode( ' · ', $facts );
	}
}
