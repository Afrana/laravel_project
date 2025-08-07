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
            if (in_array($funcName, ['var_dump', 'print_r'])) {
                $line = $node->getLine();
                $this->warnings[] = "Use of '$funcName()' found on line no {$line}";
            }
        }

        if ($node instanceof Node\Expr\Exit_) {
            $line = $node->getLine();
            $this->warnings[] = "Use of 'exit or die' found on line no {$line}";
        }
    }

    public function getWarnings() {
        return $this->warnings;
    }
}

/**
 * Additional visitor that implements various static analysis rules for PHP code.
 *
 * This visitor checks for discouraged language features (eval/goto/mysql_* functions),
 * naming conventions, long methods, nested loops, usage of superglobals,
 * magic numbers, global variables, error suppression and empty catch blocks.
 */
class CodeReviewVisitor extends NodeVisitorAbstract {
    private $warnings = [];
    private $loopDepth = 0;

    public function enterNode(Node $node) {
        // Detect use of eval()
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $funcName = $node->name->toString();
            if ($funcName === 'eval') {
                $line = $node->getLine();
                $this->warnings[] = "Use of 'eval()' found on line no {$line}";
            }
            // Detect deprecated mysql_* functions
            if (preg_match('/^mysql_/', $funcName)) {
                $line = $node->getLine();
                $this->warnings[] = "Use of '{$funcName}()' found on line no {$line}";
            }
        }

        // Detect goto statements
        if ($node instanceof Node\Stmt\Goto_) {
            $line = $node->getLine();
            $this->warnings[] = "Use of 'goto' found on line no {$line}";
        }

        // Naming conventions for functions (camelCase and verb prefix)
        if ($node instanceof Node\Stmt\Function_) {
            $name = $node->name->toString();
            // function names should be lowerCamelCase without underscores
            if (!preg_match('/^[a-z][a-zA-Z0-9]*$/', $name)) {
                $line = $node->getLine();
                $this->warnings[] = "Function '{$name}' does not follow camelCase naming convention found on line no {$line}";
            } else {
                // check verb prefix (get, set, list, delete, connect, prepare)
                if (!preg_match('/^(get|set|list|delete|connect|prepare)/', $name)) {
                    $line = $node->getLine();
                    $this->warnings[] = "Function '{$name}' should start with a verb (get/set/list/delete/connect/prepare) found on line no {$line}";
                }
            }
            // long function detection (>50 lines)
            $length = $node->getEndLine() - $node->getStartLine();
            if ($length > 50) {
                $line = $node->getLine();
                $this->warnings[] = "Function '{$name}' is too long ({$length} lines) found on line no {$line}";
            }
        }

        // Naming conventions for classes (StudlyCaps)
        if ($node instanceof Node\Stmt\Class_) {
            if ($node->name) {
                $cls = $node->name->toString();
                // Class names should start with uppercase and contain no underscores
                if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $cls)) {
                    $line = $node->getLine();
                    $this->warnings[] = "Class '{$cls}' does not follow StudlyCaps naming convention found on line no {$line}";
                }
            }
        }

        // Naming conventions for private/protected properties (underscore prefix)
        if ($node instanceof Node\Stmt\Property) {
            if ($node->isPrivate() || $node->isProtected()) {
                foreach ($node->props as $prop) {
                    $propName = $prop->name->toString();
                    if (strlen($propName) > 0 && $propName[0] !== '_') {
                        $line = $node->getLine();
                        $this->warnings[] = "Property '{$propName}' should start with an underscore found on line no {$line}";
                    }
                }
            }
        }

        // Detect use of superglobals
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            $varName = $node->name;
            $superglobals = ['_GET', '_POST', '_REQUEST', '_SERVER', '_COOKIE', '_FILES', '_ENV', '_SESSION'];
            if (in_array($varName, $superglobals)) {
                $line = $node->getLine();
                $this->warnings[] = "Use of superglobal '${$varName}' found on line no {$line}";
            }
        }

        // Detect nested loops
        if ($node instanceof Node\Stmt\For_ || $node instanceof Node\Stmt\Foreach_ || $node instanceof Node\Stmt\While_) {
            $this->loopDepth++;
            if ($this->loopDepth > 1) {
                $line = $node->getLine();
                $this->warnings[] = "Nested loop found on line no {$line}";
            }
        }

        // Detect magic numbers (exclude 0 and 1)
        if ($node instanceof Node\Scalar\LNumber) {
            $value = $node->value;
            if ($value !== 0 && $value !== 1) {
                $line = $node->getLine();
                $this->warnings[] = "Magic number '{$value}' found on line no {$line}";
            }
        }

        // Detect global statements
        if ($node instanceof Node\Stmt\Global_) {
            $line = $node->getLine();
            $this->warnings[] = "Use of 'global' found on line no {$line}";
        }

        // Detect error suppression operator
        if ($node instanceof Node\Expr\ErrorSuppress) {
            $line = $node->getLine();
            $this->warnings[] = "Use of '@' operator found on line no {$line}";
        }

        // Detect empty catch blocks
        if ($node instanceof Node\Stmt\Catch_) {
            if (empty($node->stmts)) {
                $line = $node->getLine();
                $this->warnings[] = "Empty catch block found on line no {$line}";
            }
        }
    }

    public function leaveNode(Node $node) {
        // Decrement loop depth when leaving a loop
        if ($node instanceof Node\Stmt\For_ || $node instanceof Node\Stmt\Foreach_ || $node instanceof Node\Stmt\While_) {
            $this->loopDepth--;
        }
    }

    /**
     * Retrieve accumulated warnings.
     *
     * @return array
     */
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


    // Traverse the AST with both the VarDumpVisitor and CodeReviewVisitor
    $traverser = new NodeTraverser();
    $varDumpVisitor = new VarDumpVisitor($file);
    $codeVisitor = new CodeReviewVisitor();
    $traverser->addVisitor($varDumpVisitor);
    $traverser->addVisitor($codeVisitor);
    $traverser->traverse($ast);

    // Merge warnings from both visitors
    $fileWarnings = array_merge($varDumpVisitor->getWarnings(), $codeVisitor->getWarnings());

    if (!empty($fileWarnings)) {
        $issues[] = "\nFile: $file";
        $issues = array_merge($issues, $fileWarnings);
    }
}

file_put_contents('code_review/feedback.txt', implode("\n", $issues));
echo implode("\n", $issues);