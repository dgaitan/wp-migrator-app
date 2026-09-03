<?php
/**
 * Dependency-free YAML loading for migrate_app.
 *
 * Resolution order: Symfony YAML (if the site already ships it) -> Spyc (bundled
 * with WP-CLI) -> a built-in parser that handles the flat mapping + sequence
 * subset migration.yaml actually needs. The built-in parser exists so the command
 * keeps working on a shared host where `composer require` is not an option.
 *
 * @package MigrateApp
 */

namespace MigrateApp;

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_CLI' ) ) {
	// Allows `php -l` and unit probes without a WordPress bootstrap.
}

class Yaml {

	/**
	 * Which backend handled the last parse. Printed by the command so the
	 * operator knows whether they got the real parser or the fallback.
	 *
	 * @var string
	 */
	public static $backend = 'none';

	/**
	 * Parse a YAML file into an array.
	 *
	 * @param string $path Absolute path to the file.
	 * @return array
	 * @throws \RuntimeException When the file cannot be read or parsed.
	 */
	public static function parse_file( $path ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new \RuntimeException( sprintf( 'Cannot read YAML file: %s', $path ) );
		}

		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			throw new \RuntimeException( sprintf( 'Cannot read YAML file: %s', $path ) );
		}

		return self::parse( $raw );
	}

	/**
	 * Parse a YAML string into an array, degrading across three backends.
	 *
	 * @param string $raw YAML source.
	 * @return array
	 */
	public static function parse( $raw ) {
		// 1. Symfony YAML — the real thing, if the site happens to have it.
		if ( class_exists( '\Symfony\Component\Yaml\Yaml' ) ) {
			self::$backend = 'symfony';
			$parsed        = \Symfony\Component\Yaml\Yaml::parse( $raw );
			return is_array( $parsed ) ? $parsed : array();
		}

		// 2. Spyc — ships inside WP-CLI itself.
		if ( class_exists( '\Mustangostang\Spyc' ) ) {
			self::$backend = 'spyc';
			$parsed        = \Mustangostang\Spyc::YAMLLoadString( $raw );
			return is_array( $parsed ) ? $parsed : array();
		}
		if ( class_exists( '\Spyc' ) ) {
			self::$backend = 'spyc';
			$parsed        = \Spyc::YAMLLoadString( $raw );
			return is_array( $parsed ) ? $parsed : array();
		}

		// 3. Built-in fallback.
		self::$backend = 'builtin';
		return self::parse_builtin( $raw );
	}

	/**
	 * Minimal YAML subset parser: top-level `key: value` mappings and
	 * `key:` followed by indented `- item` sequences. Handles quoted scalars,
	 * `#` comments, blank lines and a leading `---` document marker.
	 *
	 * Deliberately does NOT support nested mappings, anchors, or multi-line
	 * scalars — migration.yaml has no use for them, and a parser that silently
	 * half-supports a feature is worse than one that never claimed to.
	 *
	 * @param string $raw YAML source.
	 * @return array
	 */
	public static function parse_builtin( $raw ) {
		$out          = array();
		$current_list = null;

		$lines = preg_split( '/\r\n|\r|\n/', $raw );

		foreach ( $lines as $line ) {
			// Strip comments that are not inside a quoted scalar.
			$line = self::strip_comment( $line );

			if ( '' === trim( $line ) || '---' === trim( $line ) || '...' === trim( $line ) ) {
				continue;
			}

			// Sequence item belonging to the most recent `key:` with no value.
			if ( preg_match( '/^\s+-\s+(.*)$/', $line, $m ) && null !== $current_list ) {
				$out[ $current_list ][] = self::scalar( $m[1] );
				continue;
			}

			// Top-level (or any-indent) `key: value`.
			if ( preg_match( '/^\s*([A-Za-z0-9_.\-]+)\s*:\s*(.*)$/', $line, $m ) ) {
				$key   = $m[1];
				$value = trim( $m[2] );

				if ( '' === $value ) {
					// A key with no inline value opens a possible sequence.
					$out[ $key ]  = array();
					$current_list = $key;
					continue;
				}

				$out[ $key ]  = self::scalar( $value );
				$current_list = null;
				continue;
			}
		}

		// A key that opened a sequence but never received items reads better as null.
		foreach ( $out as $k => $v ) {
			if ( array() === $v ) {
				$out[ $k ] = null;
			}
		}

		return $out;
	}

	/**
	 * Remove a trailing `#` comment, respecting quoted scalars.
	 *
	 * @param string $line One raw line.
	 * @return string
	 */
	private static function strip_comment( $line ) {
		$in_single = false;
		$in_double = false;
		$len       = strlen( $line );

		for ( $i = 0; $i < $len; $i++ ) {
			$c = $line[ $i ];
			if ( "'" === $c && ! $in_double ) {
				$in_single = ! $in_single;
			} elseif ( '"' === $c && ! $in_single ) {
				$in_double = ! $in_double;
			} elseif ( '#' === $c && ! $in_single && ! $in_double ) {
				// Only treat as a comment when preceded by start-of-line or whitespace.
				if ( 0 === $i || ' ' === $line[ $i - 1 ] || "\t" === $line[ $i - 1 ] ) {
					return substr( $line, 0, $i );
				}
			}
		}

		return $line;
	}

	/**
	 * Coerce a YAML scalar into a PHP value.
	 *
	 * @param string $value Raw scalar text.
	 * @return string|bool|int|null
	 */
	private static function scalar( $value ) {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		// Quoted — take verbatim, minus the quotes.
		$first = $value[0];
		$last  = substr( $value, -1 );
		if ( strlen( $value ) >= 2 && ( ( '"' === $first && '"' === $last ) || ( "'" === $first && "'" === $last ) ) ) {
			$inner = substr( $value, 1, -1 );
			return '"' === $first ? stripcslashes( $inner ) : str_replace( "''", "'", $inner );
		}

		$lower = strtolower( $value );
		if ( in_array( $lower, array( 'true', 'yes', 'on' ), true ) ) {
			return true;
		}
		if ( in_array( $lower, array( 'false', 'no', 'off' ), true ) ) {
			return false;
		}
		if ( in_array( $lower, array( 'null', '~' ), true ) ) {
			return null;
		}
		if ( preg_match( '/^-?\d+$/', $value ) ) {
			return (int) $value;
		}

		return $value;
	}

	/**
	 * Render an array back out as YAML. Used by --generate-config.
	 *
	 * @param array $data   Flat map, values scalar or list.
	 * @param array $header Comment lines emitted before the document.
	 * @return string
	 */
	public static function dump( array $data, array $header = array() ) {
		$out = '';
		foreach ( $header as $line ) {
			$out .= '# ' . $line . "\n";
		}
		if ( $header ) {
			$out .= "\n";
		}

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$out .= $key . ":\n";
				foreach ( $value as $item ) {
					$out .= '  - ' . self::quote( (string) $item ) . "\n";
				}
				continue;
			}
			if ( is_bool( $value ) ) {
				$out .= $key . ': ' . ( $value ? 'true' : 'false' ) . "\n";
				continue;
			}
			if ( null === $value ) {
				$out .= $key . ': ~' . "\n";
				continue;
			}
			/*
			 * An empty scalar is written as a bare `key:` rather than `key: ""`.
			 * Both parse back to '', but `target_url:` is the form
			 * `migrate_app_pull` has always emitted and the form its e2e
			 * asserts, and an operator reading the file sees "nothing here"
			 * rather than "the empty string, deliberately". Emitted here rather
			 * than in quote(), which also renders list items where a bare `-`
			 * would be wrong.
			 */
			if ( '' === (string) $value ) {
				$out .= $key . ':' . "\n";
				continue;
			}
			$out .= $key . ': ' . self::quote( (string) $value ) . "\n";
		}

		return $out;
	}

	/**
	 * Quote a scalar for output when it could otherwise be misread.
	 *
	 * @param string $value Value to emit.
	 * @return string
	 */
	private static function quote( $value ) {
		if ( '' === $value ) {
			return '""';
		}
		if ( preg_match( '/^[A-Za-z0-9_.\/\-]+$/', $value ) ) {
			return $value;
		}
		return '"' . addcslashes( $value, '"\\' ) . '"';
	}
}
