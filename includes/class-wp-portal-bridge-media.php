<?php
/**
 * WP Portal Bridge — media/assets referenced by published routes.
 *
 * Privacy rule (hard): assets are collected ONLY from published, exposable
 * content — featured images of published posts, images referenced inside
 * published content, and attachments parented to published posts. Media that
 * exists solely on draft/private content is never enumerated.
 *
 * @package wp-portal-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Portal_Bridge_Media {

	/**
	 * Media block for a single route.
	 *
	 * @param WP_Post $post Post.
	 * @param string  $html Rendered content HTML.
	 * @return array{featuredImage: array, images: array}
	 */
	public function for_post( $post, $html ) {
		$featured = array();
		$thumb_id = get_post_thumbnail_id( $post );
		if ( $thumb_id ) {
			$featured = $this->asset( (int) $thumb_id );
		}

		$images = array();
		foreach ( $this->image_ids_from_html( $html ) as $id ) {
			$asset = $this->asset( $id );
			if ( ! empty( $asset ) ) {
				$images[] = $asset;
			}
		}

		return array(
			'featuredImage' => $featured,
			'images'        => $images,
		);
	}

	/**
	 * All assets referenced by published content, paginated.
	 *
	 * @param array<int,WP_Post> $published_posts Published posts.
	 * @param int                $page            1-based page.
	 * @param int                $per_page        Page size.
	 * @return array{assets: array, meta: array}
	 */
	public function list_assets( $published_posts, $page = 1, $per_page = 500 ) {
		$ids = array();

		foreach ( $published_posts as $post ) {
			$thumb_id = get_post_thumbnail_id( $post );
			if ( $thumb_id ) {
				$ids[ (int) $thumb_id ] = true;
			}
			// wp-image-{id} classes cover editor-inserted images without
			// rendering every post body here (cheap pass over raw content).
			if ( preg_match_all( '/wp-image-(\d+)/', (string) $post->post_content, $m ) ) {
				foreach ( $m[1] as $id ) {
					$ids[ (int) $id ] = true;
				}
			}
		}

		// Attachments parented to published posts.
		$published_ids = wp_list_pluck( $published_posts, 'ID' );
		if ( ! empty( $published_ids ) ) {
			$attached = get_posts(
				array(
					'post_type'       => 'attachment',
					'post_status'     => 'inherit',
					'post_parent__in' => $published_ids,
					'numberposts'     => -1,
					'fields'          => 'ids',
				)
			);
			foreach ( $attached as $id ) {
				$ids[ (int) $id ] = true;
			}
		}

		$ids   = array_keys( $ids );
		sort( $ids );
		$total = count( $ids );

		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( 1000, (int) $per_page ) );
		$slice    = array_slice( $ids, ( $page - 1 ) * $per_page, $per_page );

		$assets = array();
		foreach ( $slice as $id ) {
			$asset = $this->asset( $id );
			if ( ! empty( $asset ) ) {
				$assets[] = $asset;
			}
		}

		return array(
			'assets' => $assets,
			'meta'   => array(
				'total'      => $total,
				'page'       => $page,
				'perPage'    => $per_page,
				'totalPages' => (int) ceil( $total / $per_page ),
			),
		);
	}

	/**
	 * One attachment → asset contract. Empty array when not a usable attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	public function asset( $attachment_id ) {
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return array();
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			return array();
		}

		$meta   = wp_get_attachment_metadata( $attachment_id );
		$width  = is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : null;
		$height = is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : null;

		return array(
			'id'      => (int) $attachment_id,
			'url'     => $url,
			'path'    => (string) wp_parse_url( $url, PHP_URL_PATH ),
			'mime'    => (string) $post->post_mime_type,
			'width'   => $width,
			'height'  => $height,
			'alt'     => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'title'   => (string) $post->post_title,
			'caption' => (string) $post->post_excerpt,
		);
	}

	/**
	 * Attachment ids referenced in rendered HTML via wp-image-{id} classes.
	 *
	 * @param string $html Rendered HTML.
	 * @return array<int,int>
	 */
	private function image_ids_from_html( $html ) {
		$ids = array();
		if ( preg_match_all( '/wp-image-(\d+)/', (string) $html, $m ) ) {
			foreach ( $m[1] as $id ) {
				$ids[ (int) $id ] = true;
			}
		}
		return array_keys( $ids );
	}
}
