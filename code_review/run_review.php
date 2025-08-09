<?php
shell_exec("python3 code_review/fetch_pr_diff.py");

require 'vendor/autoload.php';

use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;
use PhpParser\Error;

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
        // Detect use of eval(), var_dump(), print_r() and deprecated mysql_* functions
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $funcName = $node->name->toString();
            if ($funcName === 'eval') {
                $line = $node->getLine();
                $this->warnings[] = "Use of 'eval()' found on line no {$line}";
            }
            // Detect var_dump() and print_r() used for debugging
            if (in_array($funcName, ['var_dump', 'print_r'])) {
                $line = $node->getLine();
                $this->warnings[] = "Use of '{$funcName}()' found on line no {$line}";
            }
            // Detect deprecated mysql_* functions
            if (preg_match('/^mysql_/', $funcName)) {
                $line = $node->getLine();
                $this->warnings[] = "Use of '{$funcName}()' found on line no {$line}";
            }
        }

        // Detect exit/die statements
        if ($node instanceof Node\Expr\Exit_) {
            $line = $node->getLine();
            $this->warnings[] = "Use of 'exit or die' found on line no {$line}";
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

        // Detect class constant naming (should be ALL_CAPS with underscores)
        if ($node instanceof Node\Stmt\ClassConst) {
            foreach ($node->consts as $const) {
                $constName = $const->name->toString();
                if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $constName)) {
                    $line = $const->getLine();
                    $this->warnings[] = "Class constant '{$constName}' should be in ALL_CAPS with underscores found on line no {$line}";
                }
            }
        }

        // Detect variable naming (lowerCamelCase for descriptive variables)
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            $varName = $node->name;
            // Skip superglobals (handled elsewhere) and common loop indices (i, j, k)
            $superglobals = ['_GET', '_POST', '_REQUEST', '_SERVER', '_COOKIE', '_FILES', '_ENV', '_SESSION'];
            $loopIndices = ['i', 'j', 'k', 'n', 'm'];
            if (!in_array($varName, $superglobals) && !in_array($varName, $loopIndices)) {
                if (strlen($varName) > 1 && !preg_match('/^[a-z][a-zA-Z0-9]*$/', $varName)) {
                    $line = $node->getLine();
                    $this->warnings[] = "Variable '${$varName}' does not follow lowerCamelCase naming convention found on line no {$line}";
                }
            }
        }

        // Detect missing PHPDoc on public methods
        if ($node instanceof Node\Stmt\ClassMethod) {
            // Only check methods that are explicitly public (or default public) and not magic methods
            $methodName = $node->name->toString();
            if (!$node->isPrivate() && !$node->isProtected()) {
                // Skip constructors/destructors and magic methods like __invoke
                if (!preg_match('/^__/', $methodName)) {
                    $doc = $node->getDocComment();
                    if ($doc === null) {
                        $line = $node->getLine();
                        $this->warnings[] = "Public method '{$methodName}' is missing PHPDoc comment found on line no {$line}";
                    }
                }
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
    // Detect short open tags (<? instead of <?php)
    if (strpos($code, '<?php') === false && strpos($code, '<?') !== false) {
        $issues[] = "\nFile: $file";
        $issues[] = "Short PHP opening tag detected; use <?php instead";
    }
    // Detect closing PHP tag in pure PHP files
    if (preg_match('/\?>\s*$/', $code)) {
        $issues[] = "\nFile: $file";
        $issues[] = "Closing ?> tag detected; omit the closing tag in pure PHP files";
    }

    try {
        $ast = $parser->parse($code);
    } catch (Error $e) {
        // Record parse errors as issues and skip further analysis on this file
        $issues[] = "\nFile: $file";
        $issues[] = "Parse error: " . $e->getMessage();
        continue;
    }

    // Detect single purpose per file and multiple classes
    $hasDeclaration = false;
    $hasSideEffect = false;
    $classCount = 0;
    foreach ($ast as $stmt) {
        if ($stmt instanceof Node\Stmt\Class_ || $stmt instanceof Node\Stmt\Interface_ || $stmt instanceof Node\Stmt\Function_) {
            $hasDeclaration = true;
            if ($stmt instanceof Node\Stmt\Class_) {
                $classCount++;
            }
        } elseif ($stmt instanceof Node\Stmt\Expression || $stmt instanceof Node\Stmt\Echo_ || $stmt instanceof Node\Stmt\Return_ || $stmt instanceof Node\Stmt\InlineHTML) {
            $hasSideEffect = true;
        }
    }
    if ($hasDeclaration && $hasSideEffect) {
        $issues[] = "\nFile: $file";
        $issues[] = "File contains both declarations and executable code; separate classes/functions from side effects";
    }
    if ($classCount > 1) {
        $issues[] = "\nFile: $file";
        $issues[] = "File contains multiple classes; follow one-class-per-file convention";
    }

    // Detect missing strict_types declaration for files containing declarations
    if ($hasDeclaration && strpos($code, 'declare(strict_types=1)') === false) {
        $issues[] = "\nFile: $file";
        $issues[] = "Missing declare(strict_types=1); at the top of file with class or function definitions";
    }


    // Traverse the AST with CodeReviewVisitor
    $traverser = new NodeTraverser();
    $codeVisitor = new CodeReviewVisitor();
    $traverser->addVisitor($codeVisitor);
    $traverser->traverse($ast);

    // Retrieve warnings from the visitor
    $fileWarnings = $codeVisitor->getWarnings();

    if (!empty($fileWarnings)) {
        $issues[] = "\nFile: $file";
        $issues = array_merge($issues, $fileWarnings);
    }
}

file_put_contents('code_review/feedback.txt', implode("\n", $issues));
echo implode("\n", $issues);