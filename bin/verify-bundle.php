<?php
/** Verify the imported release bundle without loading its autoloader. */
$root = isset( $argv[1] ) ? rtrim( $argv[1], DIRECTORY_SEPARATOR ) : dirname( __DIR__ );
$manifest = json_decode( file_get_contents( dirname( __DIR__ ) . '/docs/bundled-vendor-sha256.json' ), true, 512, JSON_THROW_ON_ERROR );
$actual = [];
if ( ! is_dir( $root . '/vendor' ) ) {
    fwrite( STDERR, "Bundled vendor directory is missing.\n" );
    exit( 1 );
}
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/vendor', FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
    if ( $file->isLink() || ! $file->isFile() ) {
        fwrite( STDERR, "Unexpected bundle entry: " . $file->getPathname() . "\n" );
        exit( 1 );
    }
    $path = str_replace( DIRECTORY_SEPARATOR, '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
    $actual[ $path ] = hash_file( 'sha256', $file->getPathname() );
}
ksort( $actual );
ksort( $manifest );
if ( $actual !== $manifest ) {
    foreach ( array_unique( array_merge( array_keys( $actual ), array_keys( $manifest ) ) ) as $path ) {
        if ( ( $actual[ $path ] ?? null ) !== ( $manifest[ $path ] ?? null ) ) {
            fwrite( STDERR, "Bundle checksum mismatch: " . $path . "\n" );
        }
    }
    exit( 1 );
}
echo "Verified " . count( $actual ) . " bundled vendor files.\n";
