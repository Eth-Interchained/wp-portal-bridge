<?php
/**
 * WP Portal Bridge — SEO metadata extraction.
 *
 * Authority preservation: extract what the site already earned. Supports
 * Yoast SEO, Rank Math, and AIOSEO with a WordPress-core fallback for every
 * field. Every plugin-specific read is wrapped defensively — third-party API
 * drift falls through to the fallback instead of fataling the tunnel.
 *
 * @package wp-portal-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Portal_Bridge_Seo {

	/**
	 * Detect active SEO plugins.
	 *
	 * @return array<int,array{id:string,name:string,version:string}>
	 */
	public function detect_plugins() {
		$found = array();
		if ( defined( 'WPSEO_VERSION' ) ) {
			$found[] = array(
				'id'      => 'yoast',
				'name'    => 'Yoast SEO',
				'version' => WPSEO_VERSION,
			);
		}
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			$found[] = array(
				'id'      => 'rank-math',
				'name'    => 'Rank Math',
				'version' => defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : 'unknown',
			);
		}
		if ( function_exists( 'aioseo' ) || defined( 'AIOSEO_VERSION' ) ) {
			$found[] = array(
				'id'      => 'aioseo',
				'name'    => 'All in One SEO',
				'version' => defined( 'AIOSEO_VERSION' ) ? AIOSEO_VERSION : 'unknown',
			);
		}
		return $found;
	}

	/**
	 * Extract the SEO block for a post. Plugin values win; core fallbacks fill
	 * every hole so the contract is always complete.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	public function extract( $post ) {
		$seo = array(
			'title'       => '',
			'description' => '',
			'canonical'   => '',
			'og'          => array(
				'title'       => '',
				'description' => '',
				'image'       => '',
			),
			'twitter'     => array(
				'title'       => '',
				'description' => '',
				'image'       => '',
			),
			'robots'      => array(),
			'source'      => 'fallback',
			'schemaCandidates' => $this->schema_candidates( $post ),
		);

		try {
			if ( defined( 'WPSEO_VERSION' ) ) {
				$seo = $this->merge( $seo, $this->from_yoast( $post ), 'yoast' );
			} elseif ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
				$seo = $this->merge( $seo, $this->from_rank_math( $post ), 'rank-math' );
			} elseif ( function_exists( 'aioseo' ) || defined( 'AIOSEO_VERSION' ) ) {
				$seo = $this->merge( $seo, $this->from_aioseo( $post ), 'aioseo' );
			}
		} catch ( \Throwable $e ) {
			// Third-party drift never breaks the tunnel — fall through to fallback.
		}

		return $this->apply_fallbacks( $seo, $post );
	}

	/**
	 * Yoast SEO post meta.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private function from_yoast( $post ) {
		$id = $post->ID;

		$title = (string) get_post_meta( $id, '_yoast_wpseo_title', true );
		$title = $this->resolve_yoast_vars( $title, $post );

		$desc = (string) get_post_meta( $id, '_yoast_wpseo_metadesc', true );
		$desc = $this->resolve_yoast_vars( $desc, $post );

		$robots = array();
		if ( '1' === (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			$robots[] = 'noindex';
		}
		if ( '1' === (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-nofollow', true ) ) {
			$robots[] = 'nofollow';
		}

		return array(
			'title'       => $title,
			'description' => $desc,
			'canonical'   => (string) get_post_meta( $id, '_yoast_wpseo_canonical', true ),
			'og'          => array(
				'title'       => (string) get_post_meta( $id, '_yoast_wpseo_opengraph-title', true ),
				'description' => (string) get_post_meta( $id, '_yoast_wpseo_opengraph-description', true ),
				'image'       => (string) get_post_meta( $id, '_yoast_wpseo_opengraph-image', true ),
			),
			'twitter'     => array(
				'title'       => (string) get_post_meta( $id, '_yoast_wpseo_twitter-title', true ),
				'description' => (string) get_post_meta( $id, '_yoast_wpseo_twitter-description', true ),
				'image'       => (string) get_post_meta( $id, '_yoast_wpseo_twitter-image', true ),
			),
			'robots'      => $robots,
		);
	}

	/**
	 * Resolve Yoast template variables (%%title%% etc.) when Yoast is loaded;
	 * otherwise strip unresolved tokens so templates never leak raw.
	 *
	 * @param string  $template Template string.
	 * @param WP_Post $post     Post.
	 * @return string
	 */
	private function resolve_yoast_vars( $template, $post ) {
		if ( '' === $template ) {
			return '';
		}
		if ( function_exists( 'wpseo_replace_vars' ) ) {
			try {
				return (string) wpseo_replace_vars( $template, $post );
			} catch ( \Throwable $e ) {
				// fall through
			}
		}
		if ( false !== strpos( $template, '%%' ) ) {
			return '';
		}
		return $template;
	}

	/**
	 * Rank Math post meta.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private function from_rank_math( $post ) {
		$id = $post->ID;

		$title = $this->resolve_rank_math_vars( (string) get_post_meta( $id, 'rank_math_title', true ), $post );
		$desc  = $this->resolve_rank_math_vars( (string) get_post_meta( $id, 'rank_math_description', true ), $post );

		$robots_meta = get_post_meta( $id, 'rank_math_robots', true );
		$robots      = array();
		if ( is_array( $robots_meta ) ) {
			foreach ( array( 'noindex', 'nofollow', 'noarchive', 'nosnippet' ) as $directive ) {
				if ( in_array( $directive, $robots_meta, true ) ) {
					$robots[] = $directive;
				}
			}
		}

		return array(
			'title'       => $title,
			'description' => $desc,
			'canonical'   => (string) get_post_meta( $id, 'rank_math_canonical_url', true ),
			'og'          => array(
				'title'       => (string) get_post_meta( $id, 'rank_math_facebook_title', true ),
				'description' => (string) get_post_meta( $id, 'rank_math_facebook_description', true ),
				'image'       => (string) get_post_meta( $id, 'rank_math_facebook_image', true ),
			),
			'twitter'     => array(
				'title'       => (string) get_post_meta( $id, 'rank_math_twitter_title', true ),
				'description' => (string) get_post_meta( $id, 'rank_math_twitter_description', true ),
				'image'       => (string) get_post_meta( $id, 'rank_math_twitter_image', true ),
			),
			'robots'      => $robots,
		);
	}

	/**
	 * Resolve Rank Math variables (%title% etc.) when available; strip raw tokens otherwise.
	 *
	 * @param string  $template Template string.
	 * @param WP_Post $post     Post.
	 * @return string
	 */
	private function resolve_rank_math_vars( $template, $post ) {
		if ( '' === $template ) {
			return '';
		}
		if ( class_exists( '\\RankMath\\Helper' ) && method_exists( '\\RankMath\\Helper', 'replace_vars' ) ) {
			try {
				return (string) \RankMath\Helper::replace_vars( $template, $post );
			} catch ( \Throwable $e ) {
				// fall through
			}
		}
		if ( false !== strpos( $template, '%' ) && preg_match( '/%[a-z_]+%/i', $template ) ) {
			return '';
		}
		return $template;
	}

	/**
	 * AIOSEO (v4 stores meta in its own table; the aioseo() helper is the only
	 * stable-ish surface). Best-effort with hard guards.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private function from_aioseo( $post ) {
		$out = array(
			'title'       => '',
			'description' => '',
			'canonical'   => '',
			'og'          => array( 'title' => '', 'description' => '', 'image' => '' ),
			'twitter'     => array( 'title' => '', 'description' => '', 'image' => '' ),
			'robots'      => array(),
		);

		if ( ! function_exists( 'aioseo' ) ) {
			return $out;
		}

		try {
			$aioseo = aioseo();
			if ( isset( $aioseo->meta ) && isset( $aioseo->meta->metaData ) && method_exists( $aioseo->meta->metaData, 'getMetaData' ) ) {
				$meta = $aioseo->meta->metaData->getMetaData( $post );
				if ( $meta ) {
					$out['title']       = isset( $meta->title ) ? (string) $meta->title : '';
					$out['description'] = isset( $meta->description ) ? (string) $meta->description : '';
					$out['canonical']   = isset( $meta->canonical_url ) ? (string) $meta->canonical_url : '';
					if ( isset( $meta->og_title ) ) {
						$out['og']['title'] = (string) $meta->og_title;
					}
					if ( isset( $meta->og_description ) ) {
						$out['og']['description'] = (string) $meta->og_description;
					}
					if ( isset( $meta->twitter_title ) ) {
						$out['twitter']['title'] = (string) $meta->twitter_title;
					}
					if ( isset( $meta->twitter_description ) ) {
						$out['twitter']['description'] = (string) $meta->twitter_description;
					}
					if ( isset( $meta->robots_noindex ) && $meta->robots_noindex ) {
						$out['robots'][] = 'noindex';
					}
					// AIOSEO templates use #tokens — strip unresolved template tokens.
					foreach ( array( 'title', 'description' ) as $field ) {
						if ( false !== strpos( $out[ $field ], '#' ) && preg_match( '/#[a-z_]+/i', $out[ $field ] ) ) {
							$out[ $field ] = '';
						}
					}
				}
			}
		} catch ( \Throwable $e ) {
			// Best-effort only.
		}

		return $out;
	}

	/**
	 * Merge plugin values over the base, marking the source for non-empty wins.
	 *
	 * @param array  $base   Base block.
	 * @param array  $plugin Plugin-extracted block.
	 * @param string $source Plugin id.
	 * @return array
	 */
	private function merge( $base, $plugin, $source ) {
		$won = false;
		foreach ( array( 'title', 'description', 'canonical' ) as $field ) {
			if ( ! empty( $plugin[ $field ] ) ) {
				$base[ $field ] = $plugin[ $field ];
				$won            = true;
			}
		}
		foreach ( array( 'og', 'twitter' ) as $group ) {
			foreach ( array( 'title', 'description', 'image' ) as $field ) {
				if ( ! empty( $plugin[ $group ][ $field ] ) ) {
					$base[ $group ][ $field ] = $plugin[ $group ][ $field ];
					$won                       = true;
				}
			}
		}
		if ( ! empty( $plugin['robots'] ) ) {
			$base['robots'] = array_values( array_unique( $plugin['robots'] ) );
			$won            = true;
		}
		if ( $won ) {
			$base['source'] = $source;
		}
		return $base;
	}

	/**
	 * Core fallbacks: every field guaranteed non-empty where WordPress can know it.
	 *
	 * @param array   $seo  Block after plugin extraction.
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private function apply_fallbacks( $seo, $post ) {
		if ( '' === $seo['title'] ) {
			$seo['title'] = sprintf( '%s – %s', get_the_title( $post ), get_bloginfo( 'name' ) );
		}
		if ( '' === $seo['description'] ) {
			$excerpt = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_strip_all_tags( $post->post_content );
			$seo['description'] = wp_html_excerpt( trim( preg_replace( '/\s+/', ' ', $excerpt ) ), 155, '…' );
		}
		if ( '' === $seo['canonical'] ) {
			$seo['canonical'] = get_permalink( $post );
		}

		$featured = '';
		$thumb_id = get_post_thumbnail_id( $post );
		if ( $thumb_id ) {
			$src = wp_get_attachment_image_src( $thumb_id, 'full' );
			if ( $src ) {
				$featured = $src[0];
			}
		}

		if ( '' === $seo['og']['title'] ) {
			$seo['og']['title'] = $seo['title'];
		}
		if ( '' === $seo['og']['description'] ) {
			$seo['og']['description'] = $seo['description'];
		}
		if ( '' === $seo['og']['image'] && $featured ) {
			$seo['og']['image'] = $featured;
		}
		if ( '' === $seo['twitter']['title'] ) {
			$seo['twitter']['title'] = $seo['og']['title'];
		}
		if ( '' === $seo['twitter']['description'] ) {
			$seo['twitter']['description'] = $seo['og']['description'];
		}
		if ( '' === $seo['twitter']['image'] && '' !== $seo['og']['image'] ) {
			$seo['twitter']['image'] = $seo['og']['image'];
		}

		return $seo;
	}

	/**
	 * Schema.org type candidates by post type (candidates only — Portal decides).
	 *
	 * @param WP_Post $post Post.
	 * @return array<int,string>
	 */
	private function schema_candidates( $post ) {
		switch ( $post->post_type ) {
			case 'page':
				return array( 'WebPage' );
			case 'post':
				return array( 'BlogPosting', 'Article' );
			default:
				return array( 'WebPage' );
		}
	}
}
