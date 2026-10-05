<?php
/**
 * Reads the agent log and says what it means.
 *
 * @package Make_My_Site_Agent_Ready
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "what to do next" layer: findings, not rows.
 *
 * Three questions a site owner has — am I being read, am I being cited, what is broken or being
 * ignored — answered from the agent log, the referral counts and the site's own content. Each
 * finding is one sentence, the numbers behind it, a link to the rows that show it, and an action or
 * an explicit "nothing to do".
 *
 * **What was learned building it** (spec-what-to-do-next.md, tested against 30 days of
 * miriamschwab.me): the obvious findings are often noise. "Pages no AI crawler read" was 81 of 82
 * read on that site, so it only appears when coverage is low. Most agent 404s are security probes,
 * so only paths belonging to a known standard are reported. Every finding has a threshold, because
 * a panel that fires on three rows teaches the owner to ignore it.
 *
 * **Counting.** A row whose identity check failed is never counted: it is not the crawler it named.
 * Verified rows and user-run clients count as confirmed. Everything else from a recognised name
 * (unverifiable, not yet checked) is reported alongside as "could not be confirmed". User-run
 * clients such as Claude Code are counted, by Miriam's choice, and the copy says they may be the
 * owner.
 *
 * **Computed on read, cached for an hour.** No cron, no new storage. `compute()` is pure and takes
 * everything it needs as arguments, so the rules are tested without a database.
 */
class MMSAR_Agent_Insights {

	/**
	 * Days looked back over.
	 */
	const WINDOW_DAYS = 30;

	/**
	 * Transient holding the computed findings.
	 */
	const CACHE_KEY = 'mmsar_agent_insights';

	/**
	 * The three questions, in display order.
	 */
	const QUESTIONS = array( 'read', 'cited', 'broken' );

	/**
	 * Crawler categories this layer reads.
	 */
	const AI_CATEGORIES = array( 'ai-training', 'ai-search', 'ai-assistant' );

	/**
	 * Verdicts counted as confirmed.
	 */
	const CONFIRMED = array( 'verified', 'client' );

	/**
	 * Thresholds. Low enough to say something on a small site, high enough not to report noise.
	 */
	const MIN_UPTAKE_READS    = 50;
	const MIN_TRAINING_READS  = 20;
	const MIN_ASSISTANT_READS = 3;
	const MIN_WEBMCP_CALLS    = 3;
	const MIN_STANDARD_HITS   = 2;
	const COVERAGE_BELOW      = 0.8;
	const MIN_COVERAGE_POSTS  = 10;
	const STALE_AFTER_DAYS    = 3;
	const DECLINE_GRACE_DAYS  = 2;

	/**
	 * Standards an agent may look for, keyed by id.
	 *
	 * `paths` match exactly (lowercase, no query string); `prefixes` match the start. `feature` is the
	 * toggle that publishes it here, if this plugin can. `note` says why the owner can ignore it when
	 * there is nothing to switch on.
	 */
	const STANDARDS = array(
		'a2a'         => array(
			'label'    => 'A2A agent card',
			'paths'    => array( '/.well-known/agent-card.json', '/.well-known/agent.json' ),
			'prefixes' => array(),
			'feature'  => '',
			'note'     => 'For sites that run an agent other agents can hand tasks to. This one does not, so publishing a card would point clients at nothing.',
		),
		'mcp'         => array(
			'label'    => 'MCP server discovery',
			'paths'    => array( '/.well-known/mcp.json', '/.well-known/mcp', '/.well-known/mcp/server-card.json', '/mcp' ),
			'prefixes' => array(),
			'feature'  => 'mcp_server',
			'note'     => '',
		),
		'webmcp'      => array(
			'label'    => 'WebMCP tool list',
			'paths'    => array( '/.well-known/webmcp.json', '/.well-known/webmcp' ),
			'prefixes' => array(),
			// Served only while the bridge is on as well; load_context() reports it that way.
			'feature'  => 'webmcp_manifest',
			'note'     => '',
		),
		'openapi'     => array(
			'label'    => 'OpenAPI description',
			'paths'    => array( '/openapi.json', '/.well-known/openapi.json', '/api/openapi.json', '/swagger.json', '/api/swagger.json', '/v1/openapi.json', '/openapi.yaml' ),
			'prefixes' => array(),
			'feature'  => 'openapi',
			'note'     => 'Agents guess at several addresses. Yours is /openapi.json, and it is listed in your api-catalog, which is where a careful agent looks first.',
		),
		'llms'        => array(
			'label'    => 'llms.txt',
			'paths'    => array( '/llms.txt', '/llms-full.txt' ),
			'prefixes' => array(),
			'feature'  => 'llms_txt',
			'note'     => '',
		),
		'api_catalog' => array(
			'label'    => 'api-catalog',
			'paths'    => array( '/.well-known/api-catalog' ),
			'prefixes' => array(),
			'feature'  => 'api_catalog',
			'note'     => '',
		),
		'skills'      => array(
			'label'    => 'Agent Skills index',
			'paths'    => array( '/.well-known/agent-skills/index.json', '/.well-known/agent-skills' ),
			'prefixes' => array(),
			'feature'  => 'agent_skills',
			'note'     => '',
		),
		'security'    => array(
			'label'    => 'security.txt',
			'paths'    => array( '/.well-known/security.txt', '/security.txt' ),
			'prefixes' => array(),
			'feature'  => 'security_txt',
			'note'     => '',
		),
		'nlweb'       => array(
			'label'    => 'NLWeb /ask',
			'paths'    => array( '/ask' ),
			'prefixes' => array(),
			'feature'  => 'nlweb',
			'note'     => '',
		),
		'sitemap'     => array(
			'label'    => 'sitemap.xml',
			'paths'    => array( '/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml' ),
			'prefixes' => array(),
			'feature'  => '',
			'note'     => 'Agents guess at the sitemap\'s address. Yours is named in robots.txt and the api-catalog, which is where a careful agent looks.',
		),
		'commerce'    => array(
			'label'    => 'Agentic commerce (ACP, UCP)',
			'paths'    => array( '/.well-known/ucp' ),
			'prefixes' => array( '/agentic_commerce/', '/checkout_sessions' ),
			'feature'  => '',
			'note'     => 'Checkout protocols for shops, so agents can buy on a shopper\'s behalf. Only relevant if you sell online, and then it comes from your store plugin, not this one.',
		),
		'oauth'       => array(
			'label'    => 'OAuth discovery',
			'paths'    => array( '/.well-known/oauth-authorization-server', '/.well-known/oauth-protected-resource', '/.well-known/openid-configuration' ),
			'prefixes' => array(),
			'feature'  => '',
			'note'     => 'For sites where agents log in. Everything this site offers agents is public, which /auth.md says.',
		),
		'ai_plugin'   => array(
			'label'    => 'ChatGPT plugin manifest',
			'paths'    => array( '/.well-known/ai-plugin.json' ),
			'prefixes' => array(),
			'feature'  => '',
			'note'     => 'OpenAI retired ChatGPT plugins in 2024. Old clients still ask; nothing to do.',
		),
	);

	/**
	 * Which standard a requested path belongs to, or '' for none.
	 *
	 * @param string $path Requested path, as the log stored it.
	 * @return string
	 */
	public static function standard_for_path( $path ) {
		$path = strtolower( (string) strtok( (string) $path, '?' ) );
		if ( '' === $path ) {
			return '';
		}
		$trimmed = '/' !== $path ? rtrim( $path, '/' ) : $path;
		foreach ( self::STANDARDS as $id => $standard ) {
			if ( in_array( $trimmed, $standard['paths'], true ) ) {
				return $id;
			}
			foreach ( $standard['prefixes'] as $prefix ) {
				if ( 0 === strpos( $path, $prefix ) ) {
					return $id;
				}
			}
		}
		return '';
	}

	/**
	 * A logged page path reduced to the form used to match it to a post: no query, no `.md`, no
	 * trailing slash, and '/' for the front page.
	 *
	 * @param string $detail Stored detail.
	 * @return string
	 */
	public static function page_key( $detail ) {
		$path = (string) strtok( (string) $detail, '?' );
		$path = preg_replace( '#/index\.md$#', '/', $path );
		$path = preg_replace( '#\.md$#', '', (string) $path );
		$path = rtrim( (string) $path, '/' );
		return '' === $path ? '/' : $path;
	}

	/**
	 * Builds the findings. Pure: everything comes in as arguments.
	 *
	 * @param array $reads    Page reads by AI crawlers: each `name`, `category`, `verified`, `kind`
	 *                        ('html' or 'md'), `path` (page key), `n`, `last` (UTC datetime),
	 *                        `after` (whether these reads came after the decline took effect).
	 * @param array $missing  Non-browser 404s: each `path`, `n`, `agents`.
	 * @param array $posts    Listed content, keyed by page key: each `id`, `title`, `modified` (UTC).
	 * @param array $ctx      `now` (timestamp), `features` (key => bool), `ai_train` ('yes'|'no'),
	 *                        `declined` (bool), `declined_since` (timestamp or 0), `training_tokens`
	 *                        (lowercase names), `referrals` (MMSAR_Referrals::get_summary() shape plus
	 *                        `enabled`), `forged` (count), `forged_names` (string[]),
	 *                        `unmatched_missing` (count of 404s matching no standard), `webmcp`
	 *                        (load_webmcp() shape: `calls` rows of `detail`, `arguments`, `n`;
	 *                        `networks`; `manifest`).
	 * @return array{questions: array<string, array[]>, info: array[]}
	 */
	public static function compute( array $reads, array $missing, array $posts, array $ctx ) {
		$out = array(
			'questions' => array_fill_keys( self::QUESTIONS, array() ),
			'info'      => array(),
		);

		$confirmed   = array();
		$unconfirmed = array();
		foreach ( $reads as $r ) {
			if ( 'failed' === $r['verified'] || ! in_array( $r['category'], self::AI_CATEGORIES, true ) ) {
				continue;
			}
			if ( in_array( $r['verified'], self::CONFIRMED, true ) ) {
				$confirmed[] = $r;
			} else {
				$unconfirmed[] = $r;
			}
		}

		$uptake = self::finding_uptake( $confirmed );
		if ( $uptake ) {
			$out['questions']['read'][] = $uptake;
		}
		$stale = self::finding_stale( $confirmed, $posts, $ctx );
		if ( $stale ) {
			$out['questions']['read'][] = $stale;
		}
		$coverage = self::finding_coverage( $confirmed, $posts );
		if ( $coverage ) {
			$out['questions']['read'][] = $coverage;
		}
		$webmcp = self::finding_webmcp( $ctx, $posts );
		if ( $webmcp ) {
			$out['questions']['read'][] = $webmcp;
		}

		$assistant = self::finding_assistants( $confirmed, $unconfirmed, $posts );
		if ( $assistant ) {
			$out['questions']['cited'][] = $assistant;
		}
		$referrals = self::finding_referrals( $ctx, (bool) $assistant );
		if ( $referrals ) {
			$out['questions']['cited'][] = $referrals;
		}

		$training = self::finding_training( $confirmed, $ctx );
		if ( $training ) {
			$out['questions']['broken'][] = $training;
		}
		$standards = self::finding_standards( $missing, $ctx );
		if ( $standards ) {
			$out['questions']['broken'][] = $standards;
		}

		if ( ! empty( $ctx['forged'] ) ) {
			$out['info'][] = array(
				'id'   => 'forged',
				'kind' => 'info',
				'text' => sprintf(
					/* translators: 1: number of requests, 2: crawler names */
					_n( '%1$s request in this period used the name of a crawler it wasn\'t (%2$s). The log labels these as spoofed; there is nothing to do.', '%1$s requests in this period used the name of a crawler they weren\'t (%2$s). The log labels these as spoofed; there is nothing to do.', (int) $ctx['forged'], 'make-my-site-agent-ready' ),
					number_format_i18n( (int) $ctx['forged'] ),
					implode( ', ', array_slice( (array) ( $ctx['forged_names'] ?? array() ), 0, 5 ) )
				),
			);
		}
		if ( ! empty( $ctx['unmatched_missing'] ) ) {
			$out['info'][] = array(
				'id'   => 'probes',
				'kind' => 'info',
				'text' => sprintf(
					/* translators: %s: number of requests */
					_n( '%s request was for an address that matches no agent standard, the kind of path a security scanner tries. Nothing to do.', '%s requests were for addresses that match no agent standard, the kind of paths security scanners try. Nothing to do.', (int) $ctx['unmatched_missing'], 'make-my-site-agent-ready' ),
					number_format_i18n( (int) $ctx['unmatched_missing'] )
				),
			);
		}

		return $out;
	}

	/**
	 * Sums `n` over rows, optionally only those passing a test.
	 *
	 * @param array         $rows Rows.
	 * @param callable|null $keep Test.
	 * @return int
	 */
	private static function sum( array $rows, $keep = null ) {
		$total = 0;
		foreach ( $rows as $r ) {
			if ( null === $keep || $keep( $r ) ) {
				$total += (int) $r['n'];
			}
		}
		return $total;
	}

	/**
	 * A share as a whole percentage.
	 *
	 * @param int $part  Part.
	 * @param int $whole Whole.
	 * @return int
	 */
	private static function pct( $part, $whole ) {
		return $whole > 0 ? (int) round( 100 * $part / $whole ) : 0;
	}

	/**
	 * Who reads the Markdown versions.
	 *
	 * @param array $confirmed Confirmed reads.
	 * @return array|null
	 */
	private static function finding_uptake( array $confirmed ) {
		if ( self::sum( $confirmed ) < self::MIN_UPTAKE_READS ) {
			return null;
		}
		$by_cat  = self::tally( $confirmed, 'category' );
		$by_name = self::tally( $confirmed, 'name' );

		$items = array();
		uasort(
			$by_name,
			static function ( $a, $b ) {
				return $b['all'] <=> $a['all'];
			}
		);
		foreach ( array_slice( $by_name, 0, 8, true ) as $name => $c ) {
			$items[] = array(
				'label' => $name . ' · ' . MMSAR_Agent_Log::crawler_category_label( $c['cat'] ),
				/* translators: 1: Markdown reads, 2: all reads, 3: percentage */
				'value' => sprintf( __( '%1$s of %2$s pages as Markdown (%3$s%%)', 'make-my-site-agent-ready' ), number_format_i18n( $c['md'] ), number_format_i18n( $c['all'] ), self::pct( $c['md'], $c['all'] ) ),
			);
		}

		$share = array();
		foreach ( self::AI_CATEGORIES as $cat ) {
			$share[ $cat ] = isset( $by_cat[ $cat ] ) && $by_cat[ $cat ]['all'] >= 10 ? self::pct( $by_cat[ $cat ]['md'], $by_cat[ $cat ]['all'] ) : null;
		}

		$parts = array();
		foreach ( $share as $cat => $value ) {
			if ( null !== $value ) {
				/* translators: 1: crawler category, 2: percentage */
				$parts[] = sprintf( __( '%1$s %2$s%%', 'make-my-site-agent-ready' ), MMSAR_Agent_Log::crawler_category_label( $cat ), $value );
			}
		}
		$text = sprintf(
			/* translators: %s: list of categories with percentages */
			__( 'How often AI crawlers took the Markdown version of a page instead of the HTML: %s.', 'make-my-site-agent-ready' ),
			implode( ', ', $parts )
		);

		$train  = $share['ai-training'];
		$search = $share['ai-search'];
		if ( null !== $train && null !== $search && $train - $search >= 20 ) {
			$meaning = __( 'Your Markdown mostly feeds the crawlers that train models. The search and answer engines that fetch pages live still read the HTML, so the HTML version is what they quote.', 'make-my-site-agent-ready' );
		} elseif ( null !== $search && $search >= 30 ) {
			$meaning = __( 'Search and answer engines use your Markdown, so the cleaner version is what they quote.', 'make-my-site-agent-ready' );
		} else {
			$meaning = __( 'Most AI crawlers still read the HTML. The Markdown is there for those that ask, and costs nothing when they don\'t.', 'make-my-site-agent-ready' );
		}

		return array(
			'id'       => 'uptake',
			'kind'     => 'meaning',
			'title'    => __( 'Who reads your Markdown', 'make-my-site-agent-ready' ),
			'text'     => $text . ' ' . $meaning,
			'items'    => $items,
			'evidence' => array(
				'surface' => array( 'markdown' ),
				'crawler' => self::AI_CATEGORIES,
			),
		);
	}

	/**
	 * All reads and Markdown reads, grouped by one field.
	 *
	 * @param array  $rows  Reads.
	 * @param string $field `category` or `name`.
	 * @return array<string, array{all:int, md:int, cat:string}>
	 */
	private static function tally( array $rows, $field ) {
		$out = array();
		foreach ( $rows as $r ) {
			$key = $r[ $field ];
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array(
					'all' => 0,
					'md'  => 0,
					'cat' => $r['category'],
				);
			}
			$out[ $key ]['all'] += (int) $r['n'];
			if ( 'md' === $r['kind'] ) {
				$out[ $key ]['md'] += (int) $r['n'];
			}
		}
		return $out;
	}

	/**
	 * Last confirmed read per page key for one category.
	 *
	 * @param array  $confirmed Confirmed reads.
	 * @param string $category  Category, or '' for any AI category.
	 * @return array<string, string>
	 */
	private static function last_read( array $confirmed, $category ) {
		$last = array();
		foreach ( $confirmed as $r ) {
			if ( '' !== $category && $category !== $r['category'] ) {
				continue;
			}
			if ( ! isset( $last[ $r['path'] ] ) || $r['last'] > $last[ $r['path'] ] ) {
				$last[ $r['path'] ] = $r['last'];
			}
		}
		return $last;
	}

	/**
	 * Pages changed since AI search crawlers last read them.
	 *
	 * @param array $confirmed Confirmed reads.
	 * @param array $posts     Listed content.
	 * @param array $ctx       Context.
	 * @return array|null
	 */
	private static function finding_stale( array $confirmed, array $posts, array $ctx ) {
		$last = self::last_read( $confirmed, 'ai-search' );
		if ( ! $last ) {
			return null;
		}
		$now    = (int) $ctx['now'];
		$window = gmdate( 'Y-m-d H:i:s', $now - self::WINDOW_DAYS * DAY_IN_SECONDS );
		$settle = gmdate( 'Y-m-d H:i:s', $now - self::STALE_AFTER_DAYS * DAY_IN_SECONDS );
		$stale  = array();
		foreach ( $posts as $key => $post ) {
			if ( $post['modified'] < $window || $post['modified'] > $settle ) {
				continue;
			}
			$read = isset( $last[ $key ] ) ? $last[ $key ] : '';
			if ( '' === $read || $read < $post['modified'] ) {
				$stale[ $key ] = array(
					'label' => $post['title'],
					'value' => sprintf(
						/* translators: 1: date edited, 2: date last read or "never" */
						__( 'edited %1$s, last read by AI search %2$s', 'make-my-site-agent-ready' ),
						substr( $post['modified'], 0, 16 ) . ' UTC',
						'' === $read ? __( 'never', 'make-my-site-agent-ready' ) : substr( $read, 0, 16 ) . ' UTC'
					),
					'path'  => $key,
				);
			}
		}
		if ( ! $stale ) {
			return null;
		}

		$indexnow = ! empty( $ctx['features']['indexnow'] );
		return array(
			'id'     => 'stale',
			'kind'   => 'todo',
			'title'  => __( 'Changed pages AI search hasn\'t re-read', 'make-my-site-agent-ready' ),
			'text'   => sprintf(
				/* translators: %s: number of pages */
				_n( '%s page was edited more than three days ago and no AI search crawler has read it since, so answer engines still describe the old version.', '%s pages were edited more than three days ago and no AI search crawler has read them since, so answer engines still describe the old versions.', count( $stale ), 'make-my-site-agent-ready' ),
				number_format_i18n( count( $stale ) )
			),
			'items'  => array_values( array_slice( $stale, 0, 10 ) ),
			'action' => $indexnow
				? array(
					'label' => __( 'IndexNow is on, so these were submitted when you saved them. Bing usually recrawls within days.', 'make-my-site-agent-ready' ),
					'url'   => '',
				)
				: array(
					'label' => __( 'Switch on IndexNow so search engines hear about edits when you make them', 'make-my-site-agent-ready' ),
					'url'   => 'settings#indexnow',
				),
		);
	}

	/**
	 * Listed pages no AI crawler read.
	 *
	 * @param array $confirmed Confirmed reads.
	 * @param array $posts     Listed content.
	 * @return array|null
	 */
	private static function finding_coverage( array $confirmed, array $posts ) {
		if ( count( $posts ) < self::MIN_COVERAGE_POSTS ) {
			return null;
		}
		$read = array();
		foreach ( $confirmed as $r ) {
			if ( 'ai-assistant' !== $r['category'] ) {
				$read[ $r['path'] ] = true;
			}
		}
		$unread = array_diff_key( $posts, $read );
		$share  = 1 - count( $unread ) / count( $posts );
		if ( $share >= self::COVERAGE_BELOW ) {
			return null;
		}
		uasort(
			$unread,
			static function ( $a, $b ) {
				return strcmp( $b['modified'], $a['modified'] );
			}
		);
		$items = array();
		foreach ( array_slice( $unread, 0, 10, true ) as $key => $post ) {
			$items[] = array(
				'label' => $post['title'],
				'value' => $key,
				'path'  => $key,
			);
		}
		return array(
			'id'     => 'coverage',
			'kind'   => 'todo',
			'title'  => __( 'Pages AI crawlers haven\'t read', 'make-my-site-agent-ready' ),
			'text'   => sprintf(
				/* translators: 1: pages unread, 2: pages total */
				__( '%1$s of your %2$s pages were not read by any AI training or search crawler in the last 30 days.', 'make-my-site-agent-ready' ),
				number_format_i18n( count( $unread ) ),
				number_format_i18n( count( $posts ) )
			),
			'items'  => $items,
			'action' => array(
				'label' => __( 'Crawlers find pages through links, your sitemap and llms.txt. Check these are linked from somewhere they read.', 'make-my-site-agent-ready' ),
				'url'   => '',
			),
		);
	}

	/**
	 * Agents in the browser using the site's WebMCP tools (1.58.0).
	 *
	 * Counts `tools/call` rows on the WebMCP surface. These come from a visitor's browser and claim
	 * no crawler, so there is no identity to confirm and none to forge; the rule that keeps forged
	 * identities out of every other finding has nothing to act on here. Silent below
	 * MIN_WEBMCP_CALLS, which also keeps a single test call by the site owner out of it. Each count
	 * is a floor: the log throttles one caller asking the same thing within five minutes.
	 *
	 * @param array $ctx   Context; reads `webmcp`.
	 * @param array $posts Listed content, keyed by page key.
	 * @return array|null
	 */
	private static function finding_webmcp( array $ctx, array $posts ) {
		$data    = isset( $ctx['webmcp'] ) && is_array( $ctx['webmcp'] ) ? $ctx['webmcp'] : array();
		$by_tool = array();
		$pages   = array();
		$asked   = array();
		$total   = 0;
		foreach ( isset( $data['calls'] ) ? (array) $data['calls'] : array() as $row ) {
			$detail = (string) ( $row['detail'] ?? '' );
			if ( 0 !== strpos( $detail, 'tools/call: ' ) ) {
				continue;
			}
			$n                = (int) ( $row['n'] ?? 0 );
			$tool             = substr( $detail, strlen( 'tools/call: ' ) );
			$by_tool[ $tool ] = ( $by_tool[ $tool ] ?? 0 ) + $n;
			$total           += $n;
			$args             = self::parse_arguments( (string) ( $row['arguments'] ?? '' ) );
			if ( isset( $args['url'] ) ) {
				$key           = self::page_key( $args['url'] );
				$pages[ $key ] = ( $pages[ $key ] ?? 0 ) + $n;
			}
			foreach ( array( 'query', 'topic' ) as $field ) {
				if ( isset( $args[ $field ] ) && '' !== $args[ $field ] ) {
					$asked[ $args[ $field ] ] = ( $asked[ $args[ $field ] ] ?? 0 ) + $n;
				}
			}
		}
		if ( $total < self::MIN_WEBMCP_CALLS ) {
			return null;
		}
		arsort( $by_tool );
		arsort( $pages );
		arsort( $asked );

		$tools = array();
		foreach ( $by_tool as $tool => $n ) {
			$tools[] = $tool . ' ' . number_format_i18n( $n );
		}
		$networks = (int) ( $data['networks'] ?? 0 );
		$text     = sprintf(
			/* translators: 1: number of calls, 2: number of networks, 3: per-tool counts */
			_n( 'An AI agent working in a visitor\'s browser called this site\'s WebMCP tools %1$s time, from %2$s network (%3$s), instead of reading the page.', 'AI agents working in visitors\' browsers called this site\'s WebMCP tools %1$s times, from %2$s networks (%3$s), instead of reading the page.', $total, 'make-my-site-agent-ready' ),
			number_format_i18n( $total ),
			number_format_i18n( max( 1, $networks ) ),
			implode( ', ', $tools )
		);
		if ( $asked ) {
			$quoted = array();
			foreach ( array_slice( array_keys( $asked ), 0, 3 ) as $term ) {
				$quoted[] = '"' . $term . '"';
			}
			$text .= ' ' . sprintf(
				/* translators: %s: comma-separated search terms or topics */
				__( 'They asked about %s.', 'make-my-site-agent-ready' ),
				implode( ', ', $quoted )
			);
		}
		if ( ! empty( $data['manifest'] ) ) {
			$text .= ' ' . sprintf(
				/* translators: %s: number of requests */
				_n( '/.well-known/webmcp.json, the list of these tools, was fetched %s time.', '/.well-known/webmcp.json, the list of these tools, was fetched %s times.', (int) $data['manifest'], 'make-my-site-agent-ready' ),
				number_format_i18n( (int) $data['manifest'] )
			);
		}

		$items = array();
		foreach ( array_slice( $pages, 0, 5, true ) as $key => $n ) {
			$items[] = array(
				'label' => isset( $posts[ $key ] ) ? $posts[ $key ]['title'] : $key,
				/* translators: %s: number of reads */
				'value' => sprintf( _n( 'read %s time through get_content', 'read %s times through get_content', $n, 'make-my-site-agent-ready' ), number_format_i18n( $n ) ),
				'path'  => $key,
			);
		}

		return array(
			'id'       => 'webmcp',
			'kind'     => 'meaning',
			'title'    => __( 'Agents in the browser used WebMCP', 'make-my-site-agent-ready' ),
			'text'     => $text,
			'items'    => $items,
			'evidence' => array(
				'surface' => array( MMSAR_Agent_Log::CAT_WEBMCP ),
			),
		);
	}

	/**
	 * A stored `arguments` line, as key => value.
	 *
	 * The line is space-separated `key=value` pairs (MMSAR_MCP_Arguments), where only `query` can
	 * hold spaces and is written JSON-quoted.
	 *
	 * @param string $line Stored arguments.
	 * @return array<string, string>
	 */
	public static function parse_arguments( $line ) {
		$out = array();
		if ( preg_match_all( '/(\w+)=("(?:[^"\\\\]|\\\\.)*"|\S*)/', (string) $line, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $pair ) {
				$value = $pair[2];
				if ( '' !== $value && '"' === $value[0] ) {
					$decoded = json_decode( $value );
					$value   = is_string( $decoded ) ? $decoded : trim( $value, '"' );
				}
				$out[ $pair[1] ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Pages assistants fetched to answer people.
	 *
	 * @param array $confirmed   Confirmed reads.
	 * @param array $unconfirmed Unconfirmed reads.
	 * @param array $posts       Listed content.
	 * @return array|null
	 */
	private static function finding_assistants( array $confirmed, array $unconfirmed, array $posts ) {
		$is_assistant = static function ( $r ) {
			return 'ai-assistant' === $r['category'];
		};
		$total        = self::sum( $confirmed, $is_assistant );
		if ( $total < self::MIN_ASSISTANT_READS ) {
			return null;
		}
		$client  = self::sum(
			$confirmed,
			static function ( $r ) {
				return 'ai-assistant' === $r['category'] && 'client' === $r['verified'];
			}
		);
		$by_page = array();
		$by_name = array();
		foreach ( $confirmed as $r ) {
			if ( ! $is_assistant( $r ) ) {
				continue;
			}
			$by_page[ $r['path'] ] = ( $by_page[ $r['path'] ] ?? 0 ) + (int) $r['n'];
			$by_name[ $r['name'] ] = ( $by_name[ $r['name'] ] ?? 0 ) + (int) $r['n'];
		}
		arsort( $by_page );
		arsort( $by_name );

		$items = array();
		foreach ( array_slice( $by_page, 0, 5, true ) as $key => $n ) {
			$items[] = array(
				'label' => isset( $posts[ $key ] ) ? $posts[ $key ]['title'] : $key,
				/* translators: %s: number of fetches */
				'value' => sprintf( _n( '%s fetch', '%s fetches', $n, 'make-my-site-agent-ready' ), number_format_i18n( $n ) ),
				'path'  => $key,
			);
		}

		$names = array();
		foreach ( $by_name as $name => $n ) {
			$names[] = $name . ' ' . number_format_i18n( $n );
		}
		$text = sprintf(
			/* translators: 1: number of fetches, 2: number of pages, 3: per-assistant counts */
			__( 'AI assistants fetched your pages %1$s times across %2$s pages while answering someone\'s question (%3$s). These are the pages they treat as the answer about you.', 'make-my-site-agent-ready' ),
			number_format_i18n( $total ),
			number_format_i18n( count( $by_page ) ),
			implode( ', ', $names )
		);
		if ( $client > 0 ) {
			$text .= ' ' . sprintf(
				/* translators: %s: number of fetches */
				_n( '%s of these came from a tool such as Claude Code running on someone\'s own computer, which may have been you.', '%s of these came from tools such as Claude Code running on someone\'s own computer, which may have been you.', $client, 'make-my-site-agent-ready' ),
				number_format_i18n( $client )
			);
		}
		$extra = self::sum( $unconfirmed, $is_assistant );
		if ( $extra > 0 ) {
			$text .= ' ' . sprintf(
				/* translators: %s: number of fetches */
				_n( 'Plus %s more that could not be confirmed.', 'Plus %s more that could not be confirmed.', $extra, 'make-my-site-agent-ready' ),
				number_format_i18n( $extra )
			);
		}

		return array(
			'id'       => 'assistants',
			'kind'     => 'meaning',
			'title'    => __( 'Pages assistants fetch to answer people', 'make-my-site-agent-ready' ),
			'text'     => $text,
			'items'    => $items,
			'action'   => array(
				'label' => __( 'Keep these pages current, and make sure each one says what you want an assistant to repeat.', 'make-my-site-agent-ready' ),
				'url'   => '',
			),
			'evidence' => array(
				'crawler' => array( 'ai-assistant' ),
			),
		);
	}

	/**
	 * People assistants sent.
	 *
	 * @param array $ctx           Context.
	 * @param bool  $have_fetches  Whether assistants fetched pages, which makes counting worth it.
	 * @return array|null
	 */
	private static function finding_referrals( array $ctx, $have_fetches ) {
		$ref = isset( $ctx['referrals'] ) ? $ctx['referrals'] : array();
		if ( empty( $ref['enabled'] ) ) {
			if ( ! $have_fetches ) {
				return null;
			}
			return array(
				'id'     => 'referrals',
				'kind'   => 'todo',
				'title'  => __( 'People assistants send you', 'make-my-site-agent-ready' ),
				'text'   => __( 'Assistants read your pages, but this site isn\'t counting whether people click through to them.', 'make-my-site-agent-ready' ),
				'items'  => array(),
				'action' => array(
					'label' => __( 'Switch on counting visitors sent by AI assistants', 'make-my-site-agent-ready' ),
					'url'   => 'settings#referrals',
				),
			);
		}
		if ( empty( $ref['total'] ) ) {
			return array(
				'id'    => 'referrals',
				'kind'  => 'meaning',
				'title' => __( 'People assistants send you', 'make-my-site-agent-ready' ),
				'text'  => __( 'No visitors from AI assistants counted in the last 30 days. Most assistant apps don\'t say they sent someone, so the real number may be higher. If you only just switched this on, cached pages can take a while to start counting.', 'make-my-site-agent-ready' ),
				'items' => array(),
			);
		}
		$items = array();
		foreach ( array_slice( (array) $ref['by_page'], 0, 5 ) as $row ) {
			$items[] = array(
				'label' => '(other)' === $row['path'] ? __( 'Another page', 'make-my-site-agent-ready' ) : $row['path'],
				/* translators: 1: visits, 2: assistant names */
				'value' => sprintf( __( '%1$s from %2$s', 'make-my-site-agent-ready' ), number_format_i18n( (int) $row['hits'] ), $row['assistants'] ),
				'path'  => '(other)' === $row['path'] ? '' : $row['path'],
			);
		}
		$names = array();
		foreach ( (array) $ref['by_assistant'] as $row ) {
			$names[] = $row['label'] . ' ' . number_format_i18n( (int) $row['hits'] );
		}
		return array(
			'id'    => 'referrals',
			'kind'  => 'meaning',
			'title' => __( 'People assistants send you', 'make-my-site-agent-ready' ),
			'text'  => sprintf(
				/* translators: 1: number of visits, 2: per-assistant counts */
				__( '%1$s visitors arrived from an AI assistant in the last 30 days (%2$s). A floor: many assistant apps hide where a visitor came from.', 'make-my-site-agent-ready' ),
				number_format_i18n( (int) $ref['total'] ),
				implode( ', ', $names )
			),
			'items' => $items,
		);
	}

	/**
	 * Training crawlers reading despite the owner's "no training".
	 *
	 * @param array $confirmed Confirmed reads.
	 * @param array $ctx       Context.
	 * @return array|null
	 */
	private static function finding_training( array $confirmed, array $ctx ) {
		$tokens = (array) $ctx['training_tokens'];
		$since  = '';
		if ( ! empty( $ctx['declined'] ) && ! empty( $ctx['declined_since'] ) ) {
			$since = gmdate( 'Y-m-d H:i:s', (int) $ctx['declined_since'] + self::DECLINE_GRACE_DAYS * DAY_IN_SECONDS );
		}
		$by_name = array();
		foreach ( $confirmed as $r ) {
			if ( ! in_array( strtolower( $r['name'] ), $tokens, true ) ) {
				continue;
			}
			if ( '' !== $since && empty( $r['after'] ) ) {
				continue;
			}
			$by_name[ $r['name'] ] = ( $by_name[ $r['name'] ] ?? 0 ) + (int) $r['n'];
		}
		arsort( $by_name );
		$total = array_sum( $by_name );
		$items = array();
		foreach ( array_slice( $by_name, 0, 8, true ) as $name => $n ) {
			$items[] = array(
				'label' => $name,
				/* translators: %s: number of pages */
				'value' => sprintf( _n( '%s page read', '%s pages read', $n, 'make-my-site-agent-ready' ), number_format_i18n( $n ) ),
			);
		}

		if ( ! empty( $ctx['declined'] ) ) {
			if ( '' === $since || (int) $ctx['now'] < (int) $ctx['declined_since'] + self::DECLINE_GRACE_DAYS * DAY_IN_SECONDS ) {
				return null;
			}
			if ( 0 === $total ) {
				return array(
					'id'    => 'training',
					'kind'  => 'meaning',
					'title' => __( 'Training crawlers', 'make-my-site-agent-ready' ),
					'text'  => sprintf(
						/* translators: %s: date */
						__( 'No declined training crawler has read a page since you declined them on %s. They are following your robots.txt.', 'make-my-site-agent-ready' ),
						gmdate( 'Y-m-d', (int) $ctx['declined_since'] )
					),
					'items' => array(),
				);
			}
			return array(
				'id'       => 'training',
				'kind'     => 'todo',
				'title'    => __( 'Training crawlers ignoring your robots.txt', 'make-my-site-agent-ready' ),
				'text'     => sprintf(
					/* translators: 1: number of reads, 2: date */
					__( 'Verified training crawlers read %1$s pages after you declined them on %2$s, two days\' grace included. robots.txt is a convention, and these are not following it.', 'make-my-site-agent-ready' ),
					number_format_i18n( $total ),
					gmdate( 'Y-m-d', (int) $ctx['declined_since'] )
				),
				'items'    => $items,
				'action'   => array(
					'label' => __( 'The only stronger lever is blocking them at your host or firewall, which this plugin cannot do.', 'make-my-site-agent-ready' ),
					'url'   => '',
				),
				'evidence' => array(
					'crawler' => array( 'ai-training' ),
					'verdict' => array( 'verified' ),
				),
			);
		}

		if ( 'no' !== ( $ctx['ai_train'] ?? 'no' ) || $total < self::MIN_TRAINING_READS ) {
			return null;
		}
		return array(
			'id'       => 'training',
			'kind'     => 'todo',
			'title'    => __( 'Training crawlers read you despite "no training"', 'make-my-site-agent-ready' ),
			'text'     => sprintf(
				/* translators: %s: number of reads */
				__( 'Your Content Signal says your content may not be used for training, and verified training crawlers read %s pages in the last 30 days anyway. Content Signal is a request; robots.txt currently allows them in.', 'make-my-site-agent-ready' ),
				number_format_i18n( $total )
			),
			'items'    => $items,
			'action'   => array(
				'label' => __( 'Decline AI training crawlers in robots.txt', 'make-my-site-agent-ready' ),
				'url'   => 'settings#decline-training',
			),
			'evidence' => array(
				'crawler' => array( 'ai-training' ),
				'verdict' => array( 'verified' ),
			),
		);
	}

	/**
	 * Standards agents looked for and did not find.
	 *
	 * @param array $missing 404s, matched or not.
	 * @param array $ctx     Context.
	 * @return array|null
	 */
	private static function finding_standards( array $missing, array $ctx ) {
		$hits = array();
		foreach ( $missing as $row ) {
			$id = self::standard_for_path( $row['path'] );
			if ( '' === $id ) {
				continue;
			}
			if ( ! isset( $hits[ $id ] ) ) {
				$hits[ $id ] = array(
					'n'     => 0,
					'paths' => array(),
				);
			}
			$hits[ $id ]['n']                    += (int) $row['n'];
			$hits[ $id ]['paths'][ $row['path'] ] = true;
		}

		$items = array();
		$todo  = false;
		foreach ( $hits as $id => $hit ) {
			if ( $hit['n'] < self::MIN_STANDARD_HITS ) {
				continue;
			}
			$standard = self::STANDARDS[ $id ];
			$feature  = $standard['feature'];
			$on       = '' !== $feature && ! empty( $ctx['features'][ $feature ] );
			if ( '' !== $feature && ! $on ) {
				$todo   = true;
				$advice = __( 'This plugin can publish it: switch it on in Settings.', 'make-my-site-agent-ready' );
			} elseif ( '' !== $standard['note'] ) {
				$advice = $standard['note'];
			} else {
				// Switched on now, so these requests came before it was, or from a client asking at
				// an address the feature does not serve.
				$advice = __( 'Switched on now; the requests were for an address it does not serve, or came before it was on.', 'make-my-site-agent-ready' );
			}
			$items[] = array(
				'label'   => $standard['label'],
				/* translators: 1: requests, 2: addresses */
				'value'   => sprintf( _n( '%1$s request (%2$s)', '%1$s requests (%2$s)', $hit['n'], 'make-my-site-agent-ready' ), number_format_i18n( $hit['n'] ), implode( ', ', array_slice( array_keys( $hit['paths'] ), 0, 3 ) ) ),
				'advice'  => $advice,
				'feature' => $on ? '' : $feature,
			);
		}
		if ( ! $items ) {
			return null;
		}
		return array(
			'id'       => 'standards',
			'kind'     => $todo ? 'todo' : 'meaning',
			'title'    => __( 'Standards agents looked for', 'make-my-site-agent-ready' ),
			'text'     => __( 'Agents asked for these and got a 404. Some are worth publishing; most are for other kinds of site.', 'make-my-site-agent-ready' ),
			'items'    => $items,
			'evidence' => array(
				'surface' => array( 'notfound' ),
			),
		);
	}

	/**
	 * Findings for the last 30 days, computed or from cache.
	 *
	 * @param bool $fresh Ignore the cache.
	 * @return array
	 */
	public static function get( $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$result              = self::compute( self::load_reads(), self::load_missing(), self::load_posts(), self::load_context() );
		$result['generated'] = time();
		$result['days']      = self::WINDOW_DAYS;
		set_transient( self::CACHE_KEY, $result, HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * The start of the window, as stored.
	 *
	 * @return string
	 */
	private static function since() {
		return gmdate( 'Y-m-d H:i:s', time() - self::WINDOW_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * Page reads by AI crawlers, grouped, with name and category attached.
	 *
	 * The distinct agent values are categorised in PHP first, the same way the log's crawler filter
	 * works, and only rows from AI agents are then read, matched by MD5 because user-agents can
	 * contain commas.
	 *
	 * @return array
	 */
	private static function load_reads() {
		if ( ! MMSAR_Agent_Log::table_exists() ) {
			return array();
		}
		global $wpdb;
		$table = MMSAR_Agent_Log::table();
		$since = self::since();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading this plugin's own table; the result is cached for an hour.
		$agents = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT agent FROM %i WHERE logged_at >= %s', $table, $since ) );
		$keep   = array();
		foreach ( (array) $agents as $agent ) {
			if ( in_array( MMSAR_Agent_Log::crawler_category( $agent ), self::AI_CATEGORIES, true ) ) {
				$keep[] = md5( $agent );
			}
		}
		if ( ! $keep ) {
			return array();
		}
		// Reads after the decline took effect are grouped apart, so they can be counted on their own.
		$declined = (int) get_option( 'mmsar_decline_training_since', 0 );
		$cutoff   = $declined && mmsar_declines_training() ? gmdate( 'Y-m-d H:i:s', $declined + self::DECLINE_GRACE_DAYS * DAY_IN_SECONDS ) : '9999-12-31 00:00:00';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT agent, verified, surface, detail, logged_at >= %s AS after_decline, COUNT(*) AS n, MAX(logged_at) AS last FROM %i WHERE logged_at >= %s AND FIND_IN_SET( MD5( agent ), %s ) AND ( surface LIKE %s OR surface LIKE %s ) AND detail NOT LIKE %s GROUP BY agent, verified, surface, detail, after_decline',
				$cutoff,
				$table,
				$since,
				implode( ',', $keep ),
				$wpdb->esc_like( 'HTML page view' ) . '%',
				$wpdb->esc_like( 'Markdown' ) . '%',
				$wpdb->esc_like( '/robots.txt' ) . '%'
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$name  = MMSAR_Agent_Log_Verify::claimed_name( $r['agent'] );
			$out[] = array(
				'name'     => '' !== (string) $name ? (string) $name : (string) $r['agent'],
				'category' => MMSAR_Agent_Log::crawler_category( $r['agent'] ),
				'verified' => (string) $r['verified'],
				'kind'     => 0 === strpos( (string) $r['surface'], 'Markdown' ) ? 'md' : 'html',
				'path'     => self::page_key( (string) $r['detail'] ),
				'n'        => (int) $r['n'],
				'last'     => (string) $r['last'],
				'after'    => ! empty( $r['after_decline'] ),
			);
		}
		return $out;
	}

	/**
	 * Non-browser 404s in the window, by path.
	 *
	 * @return array
	 */
	private static function load_missing() {
		if ( ! MMSAR_Agent_Log::table_exists() ) {
			return array();
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading this plugin's own table; cached for an hour.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT detail AS path, COUNT(*) AS n, COUNT(DISTINCT agent) AS agents FROM %i WHERE logged_at >= %s AND surface LIKE %s AND client_type <> %s GROUP BY detail',
				MMSAR_Agent_Log::table(),
				self::since(),
				$wpdb->esc_like( '404' ) . '%',
				MMSAR_Agent_Log::CLIENT_BROWSER
			),
			ARRAY_A
		);
		return array_map(
			static function ( $r ) {
				return array(
					'path'   => (string) $r['path'],
					'n'      => (int) $r['n'],
					'agents' => (int) $r['agents'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * WebMCP tool calls and webmcp.json fetches in the window.
	 *
	 * @return array{calls: array[], networks: int, manifest: int}
	 */
	private static function load_webmcp() {
		$out = array(
			'calls'    => array(),
			'networks' => 0,
			'manifest' => 0,
		);
		if ( ! MMSAR_Agent_Log::table_exists() ) {
			return $out;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading this plugin's own table; cached for an hour.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT detail, arguments, COUNT(*) AS n FROM %i WHERE logged_at >= %s AND surface = %s GROUP BY detail, arguments',
				MMSAR_Agent_Log::table(),
				self::since(),
				MMSAR_Agent_Log::SURFACE_WEBMCP
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $r ) {
			$out['calls'][] = array(
				'detail'    => (string) $r['detail'],
				'arguments' => (string) $r['arguments'],
				'n'         => (int) $r['n'],
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading this plugin's own table; cached for an hour.
		$out['networks'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT ip) FROM %i WHERE logged_at >= %s AND surface = %s',
				MMSAR_Agent_Log::table(),
				self::since(),
				MMSAR_Agent_Log::SURFACE_WEBMCP
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading this plugin's own table; cached for an hour.
		$out['manifest'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE logged_at >= %s AND surface = %s',
				MMSAR_Agent_Log::table(),
				self::since(),
				MMSAR_Agent_Log::SURFACE_WEBMCP_MANIFEST
			)
		);
		return $out;
	}

	/**
	 * Everything this site lists for agents, keyed by page key.
	 *
	 * @return array
	 */
	private static function load_posts() {
		$ids   = get_posts(
			MMSAR_Noindex::exclude(
				array(
					'post_type'      => mmsar_get_enabled_post_types(),
					'post_status'    => 'publish',
					'has_password'   => false,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			)
		);
		$out   = array();
		$front = (int) get_option( 'page_on_front' );
		foreach ( $ids as $id ) {
			$key         = $id === $front ? '/' : self::page_key( MMSAR_Agent_Log::request_path( (string) get_permalink( $id ) ) );
			$out[ $key ] = array(
				'id'       => (int) $id,
				'title'    => html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'modified' => (string) get_post_field( 'post_modified_gmt', $id ),
			);
		}
		if ( ! isset( $out['/'] ) ) {
			$out['/'] = array(
				'id'       => 0,
				'title'    => __( 'Homepage', 'make-my-site-agent-ready' ),
				'modified' => '0000-00-00 00:00:00',
			);
		}
		return $out;
	}

	/**
	 * Settings and counts the findings depend on.
	 *
	 * @return array
	 */
	private static function load_context() {
		$features = array();
		foreach ( array_keys( mmsar_get_feature_keys() ) as $key ) {
			$features[ $key ] = mmsar_feature_enabled( $key );
		}
		// The file needs the bridge too, so "on" means "served".
		$features['webmcp_manifest'] = MMSAR_WebMCP::manifest_enabled();
		$signals                     = get_option( 'mmsar_content_signals', array() );

		$forged = 0;
		$names  = array();
		if ( MMSAR_Agent_Log::table_exists() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading this plugin's own table; cached for an hour.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT agent, COUNT(*) AS n FROM %i WHERE logged_at >= %s AND verified = %s GROUP BY agent ORDER BY n DESC', MMSAR_Agent_Log::table(), self::since(), 'failed' ), ARRAY_A );
			foreach ( (array) $rows as $r ) {
				$forged += (int) $r['n'];
				$name    = (string) MMSAR_Agent_Log_Verify::claimed_name( $r['agent'] );
				if ( '' !== $name ) {
					$names[ $name ] = true;
				}
			}
		}

		$unmatched = 0;
		foreach ( self::load_missing() as $row ) {
			if ( '' === self::standard_for_path( $row['path'] ) ) {
				$unmatched += $row['n'];
			}
		}

		$referrals            = MMSAR_Referrals::get_summary( self::WINDOW_DAYS );
		$referrals['enabled'] = MMSAR_Referrals::is_enabled();

		return array(
			'now'               => time(),
			'features'          => $features,
			'webmcp'            => self::load_webmcp(),
			'ai_train'          => is_array( $signals ) && isset( $signals['ai_train'] ) && 'yes' === $signals['ai_train'] ? 'yes' : 'no',
			'declined'          => mmsar_declines_training(),
			'declined_since'    => (int) get_option( 'mmsar_decline_training_since', 0 ),
			'training_tokens'   => array_map( 'strtolower', mmsar_training_crawler_tokens() ),
			'referrals'         => $referrals,
			'forged'            => $forged,
			'forged_names'      => array_keys( $names ),
			'unmatched_missing' => $unmatched,
		);
	}

	/**
	 * Drops the cache, after a setting that changes a finding.
	 *
	 * @return void
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}
}
