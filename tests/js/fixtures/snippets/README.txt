Fixtures for tests/js/csp-snippet-review.test.js.

Each file is a PHP mu-plugin snippet that tests/csp-snippet-review.mjs reviews.
They are stored with a .php.txt extension on purpose: CI runs `php -l` over
every *.php file in the repository, and one of these fixtures is deliberately
unparsable. Keeping the extension off the list means the lint job stays green
while the fixtures stay readable.

Run one by hand with:

    node tests/csp-snippet-review.mjs tests/js/fixtures/snippets/good.php.txt
