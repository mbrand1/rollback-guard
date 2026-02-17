<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RG_Manifest {

	/**
	 * Write manifest data to a JSON file.
	 */
	public static function write( $file_path, $data ) {
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return @file_put_contents( $file_path, $json );
	}

	/**
	 * Read manifest data from a JSON file.
	 */
	public static function read( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return null;
		}

		$contents = @file_get_contents( $file_path );
		if ( false === $contents ) {
			return null;
		}

		$data = json_decode( $contents, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return null;
		}

		return $data;
	}
}
