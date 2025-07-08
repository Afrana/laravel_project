<?php
require 'vendor/autoload.php';

use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;

$changedFiles = explode("\n", trim(shell_exec("git diff --name-only origin/main")));

$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);

foreach ($changedFiles as $file) {
    if (!str_ends_with($file, '.php')) continue;

    echo "Reviewing file: $file\n";
    $code = file_get_contents($file);
    $ast = $parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new class extends NodeVisitorAbstract {
        public function enterNode(Node $node) {
            if ($node instanceof Node\Expr\FuncCall &&
                $node->name instanceof Node\Name &&
                $node->name->toString() === 'var_dump') {
                echo "⚠️ Warning: 'var_dump' found on line " . $node->getLine() . "\n";
            }
        }
    });

    $traverser->traverse($ast);
}
