<?php
shell_exec("python3 fetch_pr_diff.py");

require 'vendor/autoload.php';

use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;

$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
$changedFiles = file('changed_files.txt', FILE_IGNORE_NEW_LINES);

$feedback = [];

foreach ($changedFiles as $file) {
    if (!str_ends_with($file, '.php')) continue;

    $feedback[] = "Reviewing file: $file";

    $code = file_get_contents($file);
    $ast = $parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new class($file, $feedback) extends NodeVisitorAbstract {
        private $file;
        private $feedback;

        public function __construct($file, $feedback) {
            $this->file = $file;
            $this->feedback = $feedback;
        }

        public function enterNode(Node $node) {
            if ($node instanceof Node\Expr\FuncCall &&
                $node->name instanceof Node\Name &&
                $node->name->toString() === 'var_dump') {
                $line = $node->getLine();
                $this->feedback[] = "Warning!: 'var_dump()' found in `{$this->file}` on line {$line}";
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

file_put_contents("feedback.txt", implode("\n", $feedback));
echo implode("\n", $feedback);