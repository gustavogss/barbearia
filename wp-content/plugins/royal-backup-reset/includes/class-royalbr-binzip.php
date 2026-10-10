<?php
/**
 * Binary Zip Archive Engine
 *
 * Delegates zip compression to the system's binary zip executable via proc_open(),
 * keeping PHP memory usage low by running compression in a separate OS process.
 * Implements the same interface as ZipArchive for drop-in substitution.
 *
 * @package RoyalBackupReset
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Binary zip wrapper providing ZipArchive-compatible interface.
 *
 * Uses the system's /usr/bin/zip binary for compression instead of PHP's
 * ZipArchive, which holds all file data in memory until close(). This allows
 * backing up large sites (20K+ files, 400MB+) on memory-constrained shared
 * hosting without OOM kills.
 *
 * @since 1.5.0
 */
class ROYALBR_BinZip {

	/**
	 * Path to the zip archive file being created
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Path to the binary zip executable
	 *
	 * @var string
	 */
	private $binzip;

	/**
	 * Files queued for addition, keyed by base directory
	 *
	 * Each key is a base directory path and each value is an array of
	 * relative file paths to add from that directory.
	 *
	 * @var array
	 */
	private $addfiles = array();

	/**
	 * Directory entries queued for addition to the archive
	 *
	 * @var array
	 */
	private $adddirs = array();

	/**
	 * Last error message from the binary zip process
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * Additional command-line options for the zip binary (e.g., -n flag for no-compress extensions)
	 *
	 * @var string
	 */
	private $binzip_opts = '';

	/**
	 * Callable for logging messages during archive operations
	 *
	 * @var callable|null
	 */
	private $log_callback;

	/**
	 * Initialize the binary zip engine.
	 *
	 * @since 1.5.0
	 * @param string $binzip_path Path to the binary zip executable
	 * @param string $opts        Additional command-line options for the zip binary
	 */
	public function __construct( $binzip_path, $opts = '' ) {
		$this->binzip      = $binzip_path;
		$this->binzip_opts = $opts;
	}

	/**
	 * Set a callback function for logging messages.
	 *
	 * @since 1.5.0
	 * @param callable $callback Receives a single string parameter
	 */
	public function set_log_callback( $callback ) {
		$this->log_callback = $callback;
	}

	/**
	 * Log a message via the configured callback.
	 *
	 * @since 1.5.0
	 * @param string $message Message to log
	 */
	private function log( $message ) {
		if ( is_callable( $this->log_callback ) ) {
			call_user_func( $this->log_callback, $message );
		}
	}

	/**
	 * Open a zip file for writing.
	 *
	 * For the binary zip engine, this simply stores the path. The binary
	 * handles file creation/updating automatically when close() is called.
	 *
	 * @since 1.5.0
	 * @param string $path  Path to the zip file
	 * @param int    $flags Ignored (accepted for ZipArchive compatibility)
	 * @return bool Always returns true
	 */
	public function open( $path, $flags = 0 ) {
		$this->path       = $path;
		$this->addfiles   = array();
		$this->adddirs    = array();
		$this->last_error = '';
		return true;
	}

	/**
	 * Queue a file for addition to the archive.
	 *
	 * Calculates the base directory from the difference between the full path
	 * and the archive-relative path, then queues the file for batch processing
	 * during close().
	 *
	 * @since 1.5.0
	 * @param string $file   Absolute path to the file on disk
	 * @param string $add_as Relative path to store the file as inside the archive
	 */
	public function addFile( $file, $add_as ) {
		// Get the base directory: remove $add_as from the end of $file
		$add_as_len = strlen( $add_as );
		$file_len   = strlen( $file );

		if ( $file_len > $add_as_len && substr( $file, $file_len - $add_as_len ) === $add_as ) {
			$rdirname = untrailingslashit( substr( $file, 0, $file_len - $add_as_len ) );
		} else {
			$this->log( 'File skipped due to unexpected name mismatch: file=' . $file . ' add_as=' . $add_as );
			return;
		}

		$this->addfiles[ $rdirname ][] = $add_as;
	}

	/**
	 * Queue an empty directory entry for addition to the archive.
	 *
	 * @since 1.5.0
	 * @param string $dir Directory path relative to archive root
	 */
	public function addEmptyDir( $dir ) {
		$this->adddirs[] = $dir;
	}

	/**
	 * Write all queued files to the archive using the binary zip executable.
	 *
	 * Spawns /usr/bin/zip via proc_open() for each base directory group,
	 * piping file paths on stdin (-@ mode). Uses stream_select() to
	 * simultaneously write file paths and read stdout/stderr, preventing
	 * pipe buffer deadlocks.
	 *
	 * @since 1.5.0
	 * @return bool True on success, false on failure (check $last_error for details)
	 */
	public function close() {
		// Binary zip does not like zero-sized zip files
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Required for binary zip compatibility
		if ( file_exists( $this->path ) && 0 === (int) filesize( $this->path ) ) {
			@unlink( $this->path );
		}

		$descriptorspec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$exec = $this->binzip;
		if ( ! empty( $this->binzip_opts ) ) {
			$exec .= ' ' . $this->binzip_opts;
		}
		$exec .= ' -v -@ ' . escapeshellarg( $this->path );

		$last_recorded_alive = time();
		$orig_size           = file_exists( $this->path ) ? filesize( $this->path ) : 0;
		$last_size           = $orig_size;
		$something_useful    = false;
		clearstatcache();

		$added_dirs_yet = false;

		foreach ( $this->addfiles as $rdirname => $files ) {

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- proc_open may fail on restricted hosts
			$process = function_exists( 'proc_open' ) ? proc_open( $exec, $descriptorspec, $pipes, $rdirname ) : false;

			if ( ! is_resource( $process ) ) {
				$this->log( 'BinZip error: proc_open failed' );
				$this->last_error = 'BinZip error: proc_open failed';
				return false;
			}

			if ( ! $added_dirs_yet ) {
				foreach ( $this->adddirs as $dir ) {
					fwrite( $pipes[0], $dir . "/\n" );
				}
				$added_dirs_yet = true;
			}

			$read   = array( $pipes[1], $pipes[2] );
			$except = null;

			if ( ! is_array( $files ) || 0 === count( $files ) ) {
				fclose( $pipes[0] );
				$write = array();
			} else {
				$write = array( $pipes[0] );
			}

			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- stream_select may be interrupted by signals
			while ( ( ! feof( $pipes[1] ) || ! feof( $pipes[2] ) || ( is_array( $files ) && count( $files ) > 0 ) ) && false !== @stream_select( $read, $write, $except, 0, 200000 ) ) {

				if ( is_array( $write ) && in_array( $pipes[0], $write, true ) && is_array( $files ) && count( $files ) > 0 ) {
					$file = array_pop( $files );
					fwrite( $pipes[0], $file . "\n" );
					if ( 0 === count( $files ) ) {
						fclose( $pipes[0] );
					}
				}

				if ( is_array( $read ) && in_array( $pipes[1], $read, true ) ) {
					$w = fgets( $pipes[1] );

					if ( time() > $last_recorded_alive + 5 ) {
						ROYALBR_Task_Scheduler::record_still_alive();
						$last_recorded_alive = time();
					}

					if ( file_exists( $this->path ) ) {
						// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors from concurrent access
						$new_size = @filesize( $this->path );
						if ( ! $something_useful && $new_size > $orig_size + 20 ) {
							ROYALBR_Task_Scheduler::something_useful_happened();
							$something_useful = true;
						}
						clearstatcache();
						// Log at 20% growth increments or at least every 50MB
						if ( $new_size > $last_size * 1.2 || $new_size > $last_size + 52428800 ) {
							$this->log( basename( $this->path ) . sprintf( ': size is now: %.2f MB', round( $new_size / 1048576, 1 ) ) );
							$last_size = $new_size;
						}
					}
				}

				if ( is_array( $read ) && in_array( $pipes[2], $read, true ) ) {
					$stderr_line = fgets( $pipes[2] );
					if ( ! empty( $stderr_line ) ) {
						$this->last_error = rtrim( $stderr_line );
					}
				}

				// Re-set arrays for next stream_select iteration
				$read   = array( $pipes[1], $pipes[2] );
				$write  = ( is_array( $files ) && count( $files ) > 0 ) ? array( $pipes[0] ) : array();
				$except = null;
			}

			fclose( $pipes[1] );
			fclose( $pipes[2] );

			$ret = function_exists( 'proc_close' ) ? proc_close( $process ) : -1;

			if ( 0 !== $ret && 12 !== $ret ) {
				if ( $ret < 128 ) {
					$this->log( 'Binary zip: error (code: ' . $ret . ')' );
				} else {
					$this->log( 'Binary zip: error (code: ' . $ret . ' - a code above 127 normally means the zip process was killed)' );
				}
				$this->last_error = 'Binary zip failed with exit code: ' . $ret;
				return false;
			}

			unset( $this->addfiles[ $rdirname ] );
		}

		return true;
	}
}
