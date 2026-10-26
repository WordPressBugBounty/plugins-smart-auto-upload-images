<?php
/**
 * Complete URL replacement for imports and recovery.
 *
 * @package SmartAutoUploadImages
 */

namespace SmartAutoUploadImages\Services;

/**
 * Replace URL tokens without changing longer URLs or chaining replacements.
 */
class UrlReplacer {

	/**
	 * Replace complete URLs in HTML, srcset, and post text.
	 *
	 * @param string $content Post content.
	 * @param array  $replacements Source URLs mapped to replacement URLs.
	 * @return array Updated content and the distinct source URLs that were replaced.
	 */
	public static function replace( string $content, array $replacements ): array {
		$replaced_urls = [];
		if ( $replacements ) {
			$replace_url = static function ( string $url ) use ( $replacements, &$replaced_urls ): string {
				if ( ! isset( $replacements[ $url ] ) ) {
					return $url;
				}
				$replaced_urls[ $url ] = true;
				return $replacements[ $url ];
			};
			// Read attributes as a whole so decoded URL matching preserves HTML escaping.
			$pattern = '~(?<attribute>\b[a-z_:][a-z0-9_.:-]*\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))|(?:https?://|/)[^\s"\'<>]+~i';
			$content = preg_replace_callback(
				$pattern,
				static function ( array $matches ) use ( $replace_url ): string {
					$token = $matches[0];
					if ( ! empty( $matches['attribute'] ) ) {
						preg_match( '~^([^\s=]+)(\s*=\s*)(["\']?)(.*)\3$~s', $token, $attribute );
						$value = html_entity_decode( $attribute[4], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
						if ( 'srcset' === strtolower( $attribute[1] ) ) {
							$updated = preg_replace_callback(
								'~(?:https?://|/)[^\s,]+~i',
								static function ( array $urls ) use ( $replace_url ): string {
									return $replace_url( $urls[0] );
								},
								$value
							);
						} else {
							$updated = $replace_url( $value );
						}
						if ( $updated === $value ) {
							return $token;
						}
						$quote = $attribute[3] ? $attribute[3] : '"';
						return $attribute[1] . $attribute[2] . $quote . esc_attr( $updated ) . $quote;
					}
					return $replace_url( $token );
				},
				$content
			);
		}
		return [
			'content'       => $content,
			'replaced_urls' => array_keys( $replaced_urls ),
		];
	}
}
