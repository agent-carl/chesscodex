<?php
declare(strict_types=1);

/**
 * Puts the test admin login (tests/admin-test-credentials: username, then
 * password) into the local copy's config.php in place of the disabled
 * CHANGE_ME entry. Run by tools/dev/serve.sh from the repository root.
 */
[$user, $password] = file('tests/admin-test-credentials', FILE_IGNORE_NEW_LINES)
    ?: throw new RuntimeException('tests/admin-test-credentials is missing');
$line = "    'admin'         => ['username' => '$user', 'password_hash' => '"
      . password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]) . "'],";
$config = (string) file_get_contents($argv[1]);
file_put_contents($argv[1], preg_replace_callback(
    "/^.*'username' => 'CHANGE_ME'.*$/m",
    static fn(): string => $line,
    $config
));
