<?php
/**
 * Prints the GraphQL request that commits files to a branch through GitHub's API (createCommitOnBranch).
 *
 * A commit made this way with a GitHub App token is signed by GitHub, which `main` requires; a commit pushed from
 * the runner with git is not. The release workflow pipes the output to `gh api graphql --input -`.
 *
 * Usage: php bin/commit-payload.php <owner/repo> <branch> <expected head sha> <message> <file>...
 * A file that exists is added with its current contents; one that doesn't is deleted. Rehearsed by
 * `make test-release`.
 *
 * @package wp-updates-notifier
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- A command-line script that runs without WordPress.

if ( PHP_SAPI !== 'cli' || $argc < 6 ) {
	fwrite( STDERR, "usage: php bin/commit-payload.php <owner/repo> <branch> <expected head sha> <message> <file>...\n" );
	exit( 2 );
}

list( , $repo, $branch, $head, $message ) = $argv;
$files = array_slice( $argv, 5 );

$additions = array();
$deletions = array();
foreach ( $files as $file ) {
	if ( is_file( $file ) ) {
		$additions[] = array(
			'path'     => $file,
			'contents' => base64_encode( (string) file_get_contents( $file ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The API takes file contents in base64.
		);
	} else {
		$deletions[] = array( 'path' => $file );
	}
}

$file_changes = array();
if ( array() !== $additions ) {
	$file_changes['additions'] = $additions;
}
if ( array() !== $deletions ) {
	$file_changes['deletions'] = $deletions;
}

echo json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress here.
	array(
		'query'     => 'mutation($input: CreateCommitOnBranchInput!) { createCommitOnBranch(input: $input) { commit { oid url } } }',
		'variables' => array(
			'input' => array(
				'branch'          => array(
					'repositoryNameWithOwner' => $repo,
					'branchName'              => $branch,
				),
				'message'         => array( 'headline' => $message ),
				'expectedHeadOid' => $head,
				'fileChanges'     => $file_changes,
			),
		),
	),
	JSON_UNESCAPED_SLASHES
) . "\n";
