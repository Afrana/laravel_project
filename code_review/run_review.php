<?php
shell_exec("python3 code_review/fetch_pr_diff.py");

require 'vendor/autoload.php';

use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;

class VarDumpVisitor extends NodeVisitorAbstract {
    private $file;
    private $warnings = [];

    public function __construct($file) {
        $this->file = $file;
    }

    public function enterNode(Node $node) {
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $funcName = $node->name->toString();
            if (in_array($funcName, ['var_dump', 'print_r', 'die', 'exit', 'eval'])) {
                $line = $node->getLine();
                $this->warnings[] = "Use of '$funcName()' found on line no {$line}";
            }
        }
    }

    public function getWarnings() {
        return $this->warnings;
    }
}

$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
$changedFiles = file('code_review/changed_files.txt', FILE_IGNORE_NEW_LINES);

$issues = [];

foreach ($changedFiles as $file) {
    if (!str_ends_with($file, '.php')) continue;

    $code = file_get_contents($file);
    $ast = $parser->parse($code);

    $traverser = new NodeTraverser();
    $visitor = new VarDumpVisitor($file);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    $fileWarnings = $visitor->getWarnings();

    if (!empty($fileWarnings)) {
        $issues[] = "\nFile: $file";
        $issues = array_merge($issues, $fileWarnings);
    }
}

file_put_contents('code_review/feedback.txt', implode("\n", $issues));
echo implode("\n", $issues);