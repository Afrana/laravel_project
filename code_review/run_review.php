<?php
/**
 * Minimal AST-based analyzer that reads changed files and emits findings.json
 * Rules included: no_var_dump, no_print_r, no_die_exit, no_eval,
 * xss_unescaped_output (very lightweight), magic_number (simple), sqli_string_concat (simple)
 * Suppression: // review:ignore <rule> [reason: text]
 */

require 'vendor/autoload.php';

use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;
use PhpParser\Comment;

$CONFIG_PATH = "code_review/phpcodereview.json";

function load_config($path) {
    if (file_exists($path)) {
        $json = json_decode(file_get_contents($path), true);
        if (is_array($json)) return $json;
    }
    // fail-soft defaults
    return [
        "enable_rules" => [
            "no_var_dump" => true,
            "no_print_r" => true,
            "no_die_exit" => true,
            "no_eval" => true,
            "xss_unescaped_output" => true,
            "sqli_string_concat" => true,
            "magic_number" => true,
            "hardcoded_secret" => true,
        ],
        "severity" => [
            "no_var_dump" => "warning",
            "no_print_r" => "warning",
            "no_die_exit" => "critical",
            "no_eval" => "critical",
            "xss_unescaped_output" => "critical",
            "sqli_string_concat" => "critical",
            "magic_number" => "info",
            "hardcoded_secret" => "warning",
        ],
        "path_filters" => ["ignore" => ["vendor/**","node_modules/**","storage/**"]],
    ];
}

$config = load_config($CONFIG_PATH);
$enable = $config["enable_rules"] ?? [];
$sev = $config["severity"] ?? [];

$changedFiles = file_exists("code_review/changed_files.txt")
    ? array_filter(array_map("trim", file("code_review/changed_files.txt")))
    : [];

$hunks = file_exists("code_review/changed_hunks.json")
    ? json_decode(file_get_contents("code_review/changed_hunks.json"), true)
    : [];

// helper: check suppression comments near a line
function has_suppression($comments, $rule) {
    foreach ($comments as $c) {
        $txt = $c->getText();
        if (preg_match('/review:ignore\s+'.$rule.'/i', $txt)) return true;
    }
    return false;
}

class ReviewVisitor extends NodeVisitorAbstract {
    private $file;
    private $enable;
    private $sev;
    private $findings = [];

    public function __construct($file, $enable, $sev) {
        $this->file = $file;
        $this->enable = $enable;
        $this->sev = $sev;
    }

    public function addFinding($rule, $node, $message) {
        $line = $node->getLine();
        $comments = $node->getComments();
        if (has_suppression($comments, $rule)) {
            return;
        }
        $this->findings[] = [
            "file" => $this->file,
            "line" => $line,
            "rule" => $rule,
            "severity" => $this->sev[$rule] ?? "warning",
            "message" => $message,
        ];
    }

    public function enterNode(Node $node) {
        // debug functions
        if ($this->enable["no_var_dump"] ?? false) {
            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                && strtolower($node->name->toString()) === "var_dump") {
                $this->addFinding("no_var_dump", $node, "Avoid using var_dump() in committed code; use logger or assertions in tests.");
            }
        }
        if ($this->enable["no_print_r"] ?? false) {
            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                && strtolower($node->name->toString()) === "print_r") {
                $this->addFinding("no_print_r", $node, "Avoid using print_r() in committed code; use logger or structured output.");
            }
        }
        if ($this->enable["no_die_exit"] ?? false) {
            if ($node instanceof Node\Expr\Exit_) {
                $this->addFinding("no_die_exit", $node, "Avoid die()/exit(); prefer exceptions and proper error handling.");
            }
        }
        if ($this->enable["no_eval"] ?? false) {
            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                && strtolower($node->name->toString()) === "eval") {
                $this->addFinding("no_eval", $node, "Avoid eval(); it's dangerous and hard to audit.");
            }
        }

        // naive XSS: echo/print of superglobals without escaping
        if (($this->enable["xss_unescaped_output"] ?? false)
            && ($node instanceof Node\Stmt\Echo_ || $node instanceof Node\Expr\Print_)) {
            $exprs = $node instanceof Node\Stmt\Echo_ ? $node->exprs : [$node->expr];
            foreach ($exprs as $e) {
                if ($e instanceof Node\Expr\ArrayDimFetch && $e->var instanceof Node\Expr\Variable) {
                    $n = $e->var->name;
                    if (in_array($n, ["_GET","_POST","_REQUEST","_COOKIE"])) {
                        $this->addFinding("xss_unescaped_output", $node, "Unescaped output of user input; use htmlspecialchars() or framework escaping.");
                    }
                }
            }
        }

        // magic number (very simple: integer literal outside const/assign to const)
        if (($this->enable["magic_number"] ?? false) && $node instanceof Node\Scalar\LNumber) {
            // skip trivial 0/1
            if (!in_array($node->value, [0,1])) {
                $this->addFinding("magic_number", $node, "Magic number '{$node->value}' detected; replace with a named constant.");
            }
        }

        // SQLi: string concat into query-like function calls (very naive)
        if ($this->enable["sqli_string_concat"] ?? false) {
            $queryFns = ["mysql_query","mysqli_query","pg_query"];
            $queryMethods = ["select","insert","update","delete","statement","raw"];
            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                $name = strtolower($node->name->toString());
                if (in_array($name, $queryFns) && !empty($node->args)) {
                    $arg0 = $node->args[0]->value;
                    if ($arg0 instanceof Node\Expr\BinaryOp\Concat) {
                        $this->addFinding("sqli_string_concat", $node, "Possible SQL concatenation; use prepared statements/bindings.");
                    }
                }
            }
            if ($node instanceof Node\Expr\MethodCall) {
                $mname = $node->name instanceof Node\Identifier ? strtolower($node->name->name) : "";
                if (in_array($mname, $queryMethods) && !empty($node->args)) {
                    $arg0 = $node->args[0]->value;
                    if ($arg0 instanceof Node\Expr\BinaryOp\Concat) {
                        $this->addFinding("sqli_string_concat", $node, "Possible SQL concatenation in method call; prefer bindings.");
                    }
                }
            }
            if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name) {
                $class = strtolower($node->class->toString());
                $mname = $node->name instanceof Node\Identifier ? strtolower($node->name->name) : "";
                if (in_array($mname, ["select","statement","raw"]) && !empty($node->args)) {
                    $arg0 = $node->args[0]->value;
                    if ($arg0 instanceof Node\Expr\BinaryOp\Concat) {
                        $this->addFinding("sqli_string_concat", $node, "Possible SQL concatenation in static DB call; prefer bindings.");
                    }
                }
            }
        }
        return null;
    }

    public function getFindings() {
        return $this->findings;
    }
}

$parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
$traverser = new NodeTraverser();

$allFindings = [];

foreach ($changedFiles as $file) {
    if (!file_exists($file)) continue;
    $code = file_get_contents($file);
    try {
        $ast = $parser->parse($code);
        $visitor = new ReviewVisitor($file, $enable, $sev);
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        $traverser->removeVisitor($visitor);
        $fileFindings = $visitor->getFindings();

        // filter to changed hunks (line-based) here too as a second gate
        $ranges = $hunks[$file] ?? [];
        $kept = [];
        foreach ($fileFindings as $f) {
            $line = $f["line"];
            $ok = false;
            foreach ($ranges as $r) {
                $start = $r[0]; $len = $r[1];
                if ($line >= $start && $line < $start + $len) { $ok = true; break; }
            }
            if ($ok) $kept[] = $f;
        }
        $allFindings = array_merge($allFindings, $kept);
    } catch (Throwable $e) {
        // record a tool error as info
        $allFindings[] = [
            "file" => $file,
            "line" => 1,
            "rule" => "analyzer_error",
            "severity" => "info",
            "message" => "Analyzer failed on this file: " . $e->getMessage()
        ];
    }
}

if (!is_dir("code_review")) { mkdir("code_review", 0777, true); }
file_put_contents("code_review/findings.json", json_encode($allFindings, JSON_PRETTY_PRINT));
echo "Wrote " . count($allFindings) . " findings to code_review/findings.json\n";