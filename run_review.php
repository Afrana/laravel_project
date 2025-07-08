<?php
shell_exec("python3 fetch_pr_diff.py");

require 'vendor/autoload.php';

use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;

// $changedFiles = explode("\n", trim(shell_exec("git diff --name-only origin/main")));

$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
$changedFiles = file('changed_files.txt', FILE_IGNORE_NEW_LINES);

foreach ($changedFiles as $file) {
    if (!str_ends_with($file, '.php')) continue;

    $issues[] = "Reviewing file: $file\n";
    $code = file_get_contents($file);
    $ast = $parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new class extends NodeVisitorAbstract {
        public function enterNode(Node $node) {
            if ($node instanceof Node\Expr\FuncCall &&
                $node->name instanceof Node\Name &&
                $node->name->toString() === 'var_dump') {
                $issues[] = "Warning !: 'var_dump' found on line " . $node->getLine() . "\n";
            }
        }
    });

    $traverser->traverse($ast);
    
    // $issues = [];
    // $lines = file($file);
    // foreach ($lines as $num => $line) {
    //     if (strpos($line, 'var_dump') !== false) {
    //         $issues[] = "Found var_dump() in `$file` on line " . ($num + 1);
    //     }
    // }
}


file_put_contents('feedback.txt', implode("\n", $issues));