<?php
shell_exec("python3 code_review/fetch_pr_diff.py");

require 'vendor/autoload.php';

use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;
use PhpParser\Error;

// VarDumpVisitor logic has been merged into CodeReviewVisitor.  We keep this
// placeholder class for backward compatibility, but it is no longer used.

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
    // Stack of contexts to track variable assignments and uses within functions/methods
    private $functionContexts = [];
    // Stack to track the variable being assigned to avoid counting the left-hand side as a use
    private $assignmentVarStack = [];

    public function enterNode(Node $node) {
        // When entering a function or class method, initialise a new context for tracking
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            $this->functionContexts[] = [
                'assigns' => [],
                'uses'    => [],
                'node'    => $node,
            ];
        }
        // Detect use of eval(), var_dump(), print_r() and deprecated mysql_* functions
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $funcName = $node->name->toString();
            if ($funcName === 'eval') {
                $line = $node->getLine();
                // Suggest avoiding eval
                $this->warnings[] = "Use of 'eval()' found on line no {$line}; avoid eval and use a safer alternative (e.g., call_user_func or proper function calls)";
            }
            // Detect var_dump() and print_r() used for debugging
            if (in_array($funcName, ['var_dump', 'print_r'])) {
                $line = $node->getLine();
                $this->warnings[] = "Use of '{$funcName}()' found on line no {$line}; remove debug statements or use a proper logging mechanism";
            }
            // Detect deprecated mysql_* functions
            if (preg_match('/^mysql_/', $funcName)) {
                $line = $node->getLine();
                $this->warnings[] = "Use of '{$funcName}()' found on line no {$line}; these functions are deprecated, use PDO or MySQLi instead";
            }
        }

        // Detect exit/die statements
        if ($node instanceof Node\Expr\Exit_) {
            $line = $node->getLine();
            $this->warnings[] = "Use of 'exit or die' found on line no {$line}; throw an exception or return instead of abruptly terminating the script";
        }

        // Detect goto statements
        if ($node instanceof Node\Stmt\Goto_) {
            $line = $node->getLine();
            $this->warnings[] = "Use of 'goto' found on line no {$line}; restructure the control flow to avoid goto";
        }

        // Naming conventions for functions (camelCase and verb prefix)
        if ($node instanceof Node\Stmt\Function_) {
            $name = $node->name->toString();
            // function names should be lowerCamelCase without underscores
            if (!preg_match('/^[a-z][a-zA-Z0-9]*$/', $name)) {
                $line = $node->getLine();
                $this->warnings[] = "Function '{$name}' does not follow camelCase naming convention found on line no {$line}; rename it using lowerCamelCase";
            } else {
                // check verb prefix (get, set, list, delete, connect, prepare)
                if (!preg_match('/^(get|set|list|delete|connect|prepare)/', $name)) {
                    $line = $node->getLine();
                    $this->warnings[] = "Function '{$name}' should start with a verb (get/set/list/delete/connect/prepare) found on line no {$line}; pick a more descriptive verb-based name";
                }
            }
            // long function detection (>50 lines)
            $length = $node->getEndLine() - $node->getStartLine();
            if ($length > 50) {
                $line = $node->getLine();
                $this->warnings[] = "Function '{$name}' is too long ({$length} lines) found on line no {$line}; consider splitting it into smaller functions";
            }
        }

        // Naming conventions for classes (StudlyCaps)
        if ($node instanceof Node\Stmt\Class_) {
            if ($node->name) {
                $cls = $node->name->toString();
                // Class names should start with uppercase and contain no underscores
                if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $cls)) {
                    $line = $node->getLine();
                    $this->warnings[] = "Class '{$cls}' does not follow StudlyCaps naming convention found on line no {$line}; rename the class using StudlyCaps";
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
                        $this->warnings[] = "Property '{$propName}' should start with an underscore found on line no {$line}; prefix private/protected properties with '_'";
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
                $this->warnings[] = "Use of superglobal '${$varName}' found on line no {$line}; use a request abstraction or sanitised input";
            }
        }

        // Detect nested loops
        if ($node instanceof Node\Stmt\For_ || $node instanceof Node\Stmt\Foreach_ || $node instanceof Node\Stmt\While_) {
            $this->loopDepth++;
            if ($this->loopDepth > 1) {
                $line = $node->getLine();
                $this->warnings[] = "Nested loop found on line no {$line}; refactor to reduce complexity (e.g., extract inner loop into a separate function)";
            }
        }

        // Detect magic numbers (exclude 0 and 1)
        if ($node instanceof Node\Scalar\LNumber) {
            $value = $node->value;
            if ($value !== 0 && $value !== 1) {
                $line = $node->getLine();
                $this->warnings[] = "Magic number '{$value}' found on line no {$line}; replace it with a named constant";
            }
        }

        // Detect hard-coded values in assignments
        if ($node instanceof Node\Expr\Assign) {
            $valueNode = $node->expr;
            // Check for string or numeric literal assignment
            if ($valueNode instanceof Node\Scalar\String_ || $valueNode instanceof Node\Scalar\LNumber || $valueNode instanceof Node\Scalar\DNumber) {
                // Skip small numeric literals (0 or 1) as these may be used legitimately
                if (!($valueNode instanceof Node\Scalar\LNumber && ($valueNode->value === 0 || $valueNode->value === 1))) {
                    $line = $valueNode->getLine();
                    // Truncate long strings for readability
                    $value = $valueNode->value;
                    if (is_string($value)) {
                        $display = substr($value, 0, 30);
                        if (strlen($value) > 30) {
                            $display .= '...';
                        }
                    } else {
                        $display = $value;
                    }
                    $this->warnings[] = "Hard-coded value '{$display}' assigned on line no {$line}; move this value into a configuration constant or environment variable";
                }
            }
        }

        // Detect class constant naming (should be ALL_CAPS with underscores)
        if ($node instanceof Node\Stmt\ClassConst) {
            foreach ($node->consts as $const) {
                $constName = $const->name->toString();
                if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $constName)) {
                    $line = $const->getLine();
                    $this->warnings[] = "Class constant '{$constName}' should be in ALL_CAPS with underscores found on line no {$line}; rename the constant accordingly";
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
                    $this->warnings[] = "Variable '${$varName}' does not follow lowerCamelCase naming convention found on line no {$line}; rename the variable using lowerCamelCase with descriptive words";
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
                        $this->warnings[] = "Public method '{$methodName}' is missing PHPDoc comment found on line no {$line}; add a PHPDoc block describing parameters and return types";
                    }
                }
            }
        }

        // Detect global statements
        if ($node instanceof Node\Stmt\Global_) {
            $line = $node->getLine();
            $this->warnings[] = "Use of 'global' found on line no {$line}; avoid global variables by passing dependencies explicitly";
        }

        // Detect error suppression operator
        if ($node instanceof Node\Expr\ErrorSuppress) {
            $line = $node->getLine();
            $this->warnings[] = "Use of '@' operator found on line no {$line}; do not suppress errors with @, handle them properly";
        }

        // Detect empty catch blocks
        if ($node instanceof Node\Stmt\Catch_) {
            if ($this->isEmptyBlock($node->stmts)) {
                $line = $node->getLine();
                $this->warnings[] = "Empty catch block found on line no {$line}; handle exceptions or at least log them";
            }
        }

        // Empty finally
        if ($node instanceof Node\Stmt\Finally_) {
            if ($this->isEmptyBlock($node->stmts)) {
                $this->warnings[] = "Empty finally block (line {$node->getLine()}); add cleanup or remove.";
            }
        }

        // Empty if / elseif / else
        if ($node instanceof Node\Stmt\If_) {
            if ($this->isEmptyBlock($node->stmts)) {
                $this->warnings[] = "Empty if block (line {$node->getLine()}); implement logic or remove.";
            }
            foreach ($node->elseifs as $elseif) {
                if ($this->isEmptyBlock($elseif->stmts)) {
                    $this->warnings[] = "Empty elseif block (line {$elseif->getLine()}); implement logic or remove.";
                }
            }
            if ($node->else && $this->isEmptyBlock($node->else->stmts)) {
                $this->warnings[] = "Empty else block (line {$node->else->getLine()}); implement logic or remove.";
            }
        }

        // Empty foreach / for / while / do-while
        if ($node instanceof Node\Stmt\Foreach_) {
            if ($this->isEmptyBlock($node->stmts)) {
                $this->warnings[] = "Empty foreach body (line {$node->getLine()}); implement logic or remove.";
            }
        }
        if ($node instanceof Node\Stmt\For_) {
            if ($this->isEmptyBlock($node->stmts)) {
                $this->warnings[] = "Empty for loop body (line {$node->getLine()}); implement logic or remove.";
            }
        }
        if ($node instanceof Node\Stmt\While_) {
            if ($this->isEmptyBlock($node->stmts)) {
                $this->warnings[] = "Empty while loop body (line {$node->getLine()}); implement logic or remove.";
            }
        }
        if ($node instanceof Node\Stmt\Do_) {
            if ($this->isEmptyBlock($node->stmts)) {
                $this->warnings[] = "Empty do-while loop body (line {$node->getLine()}); implement logic or remove.";
            }
        }

        // Empty switch cases
        if ($node instanceof Node\Stmt\Switch_) {
            foreach ($node->cases as $case) {
                if ($this->isEmptyBlock($case->stmts)) {
                    $label = $case->cond ? 'case' : 'default';
                    $this->warnings[] = "Empty {$label} block in switch (line {$case->getLine()}); implement logic or remove.";
                }
            }
        }

        // Empty functions and methods (skip abstract/interface)
        if ($node instanceof Node\Stmt\Function_) {
            if ($this->isEmptyBlock($node->stmts)) {
                $name = $node->name->toString();
                $this->warnings[] = "Empty function '{$name}' (line {$node->getLine()}); add implementation or remove.";
            }
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            // $node->stmts === null for abstract/interface; skip those
            if (!$node->isAbstract() && is_array($node->stmts) && $this->isEmptyBlock($node->stmts)) {
                $name = $node->name->toString();
                $this->warnings[] = "Empty method '{$name}' (line {$node->getLine()}); add implementation or remove.";
            }
        }

        // ---------------------------------------------------------------------
        // Variable tracking for unused variable detection
        // ---------------------------------------------------------------------
        // Record variable assignment inside functions/methods
        if ($node instanceof Node\Expr\Assign && !empty($this->functionContexts)) {
            // Only consider assignments to simple variables (not properties or array items)
            if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                $varName = $node->var->name;
                $ctxIndex = count($this->functionContexts) - 1;
                if (!isset($this->functionContexts[$ctxIndex]['assigns'][$varName])) {
                    $this->functionContexts[$ctxIndex]['assigns'][$varName] = [];
                }
                // Record the line where the assignment occurs
                $this->functionContexts[$ctxIndex]['assigns'][$varName][] = $node->getLine();
                // Push the variable name onto the assignment stack to avoid counting this occurrence as usage
                $this->assignmentVarStack[] = $varName;
            }
        }
        // Record variable usages inside functions/methods
        if ($node instanceof Node\Expr\Variable && !empty($this->functionContexts)) {
            if (is_string($node->name)) {
                $varName = $node->name;
                // Determine if this occurrence should be counted as a usage or skipped because it's a left-hand side of an assignment
                $skip = false;
                if (!empty($this->assignmentVarStack)) {
                    $currentAssignVar = end($this->assignmentVarStack);
                    if ($currentAssignVar === $varName) {
                        $skip = true;
                    }
                }
                if (!$skip) {
                    $ctxIndex = count($this->functionContexts) - 1;
                    if (!isset($this->functionContexts[$ctxIndex]['uses'][$varName])) {
                        $this->functionContexts[$ctxIndex]['uses'][$varName] = 0;
                    }
                    $this->functionContexts[$ctxIndex]['uses'][$varName]++;
                }
            }
        }
    }

    public function leaveNode(Node $node) {
        // Pop assignment variable stack when leaving an assignment
        if ($node instanceof Node\Expr\Assign && !empty($this->assignmentVarStack)) {
            array_pop($this->assignmentVarStack);
        }

        // Decrement loop depth when leaving a loop
        if ($node instanceof Node\Stmt\For_ || $node instanceof Node\Stmt\Foreach_ || $node instanceof Node\Stmt\While_) {
            $this->loopDepth--;
        }

        // When leaving a function or method, evaluate unused variables in the context
        if (($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) && !empty($this->functionContexts)) {
            $context = array_pop($this->functionContexts);
            $assigns = $context['assigns'];
            $uses    = $context['uses'];
            $functionName = $node->name instanceof Node\Identifier ? $node->name->toString() : '';
            foreach ($assigns as $varName => $lines) {
                // If variable never used, record a warning for each assignment line
                if (!array_key_exists($varName, $uses)) {
                    foreach ($lines as $assignLine) {
                        $this->warnings[] = "Variable '$$varName' assigned but never used in function/method '{$functionName}' on line no {$assignLine}; remove or use the variable";
                    }
                }
            }
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
    
    private function isEmptyBlock(?array $stmts): bool
    {
        if (!$stmts || count($stmts) === 0) {
            return true;
        }
        foreach ($stmts as $s) {
            if (!($s instanceof PhpParser\Node\Stmt\Nop)) {
                return false;
            }
        }
        return true; // only Nops/comments
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
        $issues[] = "Short PHP opening tag detected; use <?php instead to ensure portability";
    }
    // Detect closing PHP tag in pure PHP files
    if (preg_match('/\?>\s*$/', $code)) {
        $issues[] = "\nFile: $file";
        $issues[] = "Closing ?> tag detected; omit the closing tag in pure PHP files to prevent unintended output";
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
        $issues[] = "File contains both declarations and executable code; separate classes and functions into their own files without side effects";
    }
    if ($classCount > 1) {
        $issues[] = "\nFile: $file";
        $issues[] = "File contains multiple classes; follow one-class-per-file convention";
    }

    // Detect missing strict_types declaration for files containing declarations
    if ($hasDeclaration && strpos($code, 'declare(strict_types=1)') === false) {
        $issues[] = "\nFile: $file";
        $issues[] = "Missing declare(strict_types=1); add \"declare(strict_types=1);\" at the top of files defining classes or functions";
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