<?php

namespace igorw\reasoned;

// microKanren implementation

class Variable {
    public $name;
    function __construct($name) {
        $this->name = $name;
    }
    function is_equal(Variable $var) {
        return $this->name === $var->name;
    }
}

function variable($name) {
    return new Variable($name);
}

function is_variable($x) {
    return $x instanceof Variable;
}

class Substitution {
    public $values;
    function __construct(array $values = []) {
        $this->values = $values;
    }
    function walk($u) {
        if (is_variable($u) && $value = $this->find($u)) {
            return $this->walk($value);
        }
        return $u;
    }
    function find(Variable $var) {
        foreach ($this->values as list($x, $value)) {
            if ($var->is_equal($x)) {
                return $value;
            }
        }
        return null;
    }
    function extend(Variable $x, $value) {
        return new Substitution(array_merge(
            [[$x, $value]],
            $this->values
        ));
    }
    function length() {
        return count($this->values);
    }
    function reify($v) {
        $v = $this->walk($v);
        if (is_variable($v)) {
            $n = reify_name($this->length());
            return $this->extend($v, $n);
        }
        if (is_pair($v)) {
            return $this->reify(first($v))
                        ->reify(rest($v));
        }
        return $this;
    }
}

class State {
    public $subst;
    public $count;
    function __construct(Substitution $subst = null, $count = 0) {
        $this->subst = $subst ?: new Substitution();
        $this->count = $count;
    }
    function next() {
        return new State($this->subst, $this->count + 1);
    }
    function reify() {
        $v = walk_star(variable(0), $this->subst);
        return walk_star($v, (new Substitution())->reify($v));
    }
}

function eq($u, $v) {
    return function (State $state) use ($u, $v) {
        $subst = unify($u, $v, $state->subst);
        if ($subst) {
            return unit(new State($subst, $state->count));
        }
        return mzero();
    };
}

function unit(State $state) {
    return cons($state, mzero());
}

function mzero() {
    return [];
}

// really just means non-empty array
function is_pair($value) {
    return is_array($value) && count($value) > 0;
}

function unify($u, $v, Substitution $subst) {
    $u = $subst->walk($u);
    $v = $subst->walk($v);

    if (is_variable($u) && is_variable($v) && $u->is_equal($v)) {
        return $subst;
    }
    if (is_variable($u)) {
        return $subst->extend($u, $v);
    }
    if (is_variable($v)) {
        return $subst->extend($v, $u);
    }
    if (is_pair($u) && is_pair($v)) {
        $subst = unify(first($u), first($v), $subst);
        return $subst ? unify(rest($u), rest($v), $subst) : null;
    }
    if ($u === $v) {
        return $subst;
    }
    return null;
}

function call_fresh(callable $f) {
    return function (State $state) use ($f) {
        $res = $f(variable($state->count));
        return $res($state->next());
    };
}

function disj(callable $goal1, callable $goal2) {
    return function (State $state) use ($goal1, $goal2) {
        return mplus($goal1($state), $goal2($state));
    };
}

function conj(callable $goal1, callable $goal2) {
    return function (State $state) use ($goal1, $goal2) {
        return bind($goal1($state), $goal2);
    };
}

function cons($value, array $stream) {
    array_unshift($stream, $value);
    return $stream;
}

function first(array $stream) {
    return array_shift($stream);
}

function rest(array $stream) {
    array_shift($stream);
    return $stream;
}

function mplus($stream1, $stream2) {
    if ($stream1 === []) {
        return $stream2;
    }
    if (is_callable($stream1)) {
        return function () use ($stream1, $stream2) {
            return mplus($stream2, $stream1());
        };
    }
    return cons(first($stream1), mplus(rest($stream1), $stream2));
}

function bind($stream, callable $goal) {
    if ($stream === []) {
        return mzero();
    }
    if (is_callable($stream)) {
        return function () use ($stream, $goal) {
            return bind($stream(), $goal);
        };
    }
    return mplus($goal(first($stream)), bind(rest($stream), $goal));
}

// recovering miniKanren's control operators

function zzz(callable $goal) {
    return function (State $state) use ($goal) {
        return function () use ($goal, $state) {
            return $goal($state);
        };
    };
}

function conj_plus(array $goals) {
    if (count($goals) === 0) {
        throw new \InvalidArgumentException('Must supply at least one goal');
    }
    if (count($goals) === 1) {
        return zzz(first($goals));
    }
    return conj(zzz(first($goals)), conj_plus(rest($goals)));
}

function disj_plus(array $goals) {
    if (count($goals) === 0) {
        throw new \InvalidArgumentException('Must supply at least one goal');
    }
    if (count($goals) === 1) {
        return zzz(first($goals));
    }
    return disj(zzz(first($goals)), disj_plus(rest($goals)));
}

function conde(array $lines) {
    return disj_plus(array_map('igorw\reasoned\conj_plus', $lines));
}

function fresh(callable $f) {
    $argCount = (new \ReflectionFunction($f))->getNumberOfParameters();
    if ($argCount === 0) {
        return $f();
    }
    return call_fresh(function ($x) use ($f, $argCount) {
        return collect_args($f, $argCount, [$x]);
    });
}

function collect_args(callable $f, $argCount, $args) {
    if (count($args) === $argCount) {
        return call_user_func_array($f, $args);
    }

    return call_fresh(function ($x) use ($f, $argCount, $args) {
        return collect_args($f, $argCount, array_merge($args, [$x]));
    });
}

// from streams to lists

function pull($stream) {
    if (is_callable($stream)) {
        return pull($stream());
    }
    return $stream;
}

function take_all($stream) {
    $stream = pull($stream);
    if ($stream === []) {
        return [];
    }
    return cons(first($stream), take_all(rest($stream)));
}

function take($n, $stream) {
    if ($n === 0) {
        return [];
    }
    $stream = pull($stream);
    if ($stream === []) {
        return [];
    }
    return cons(first($stream), take($n - 1, rest($stream)));
}

// recovering reification

function reify(array $states) {
    return array_map(function (State $state) { return $state->reify(); }, $states);
}

function reify_name($n) {
    return "_.$n";
}

function walk_star($v, Substitution $subst) {
    $v = $subst->walk($v);
    if (is_variable($v)) {
        return $v;
    }
    if (is_pair($v)) {
        return cons(walk_star(first($v), $subst), walk_star(rest($v), $subst));
    }
    return $v;
}

// recovering the scheme interface

function call_goal($goal) {
    return $goal(new State());
}

function run($n, $goal) {
    return reify(take($n, call_goal(fresh($goal))));
}

function run_star($goal) {
    return reify(take_all(call_goal(fresh($goal))));
}

var_dump(run_star(function ($x) {
    return conj(
        eq($x, 'a'),
        eq($x, 'b')
    );
}));

var_dump(run_star(function ($x) {
    return disj(
        eq($x, 'a'),
        eq($x, 'b')
    );
}));

var_dump(run_star(function ($x, $y) {
    return eq($x, $y);
}));

var_dump(run_star(function ($q, $a, $b) {
    return conj_plus([
        eq([$a, $b], ['a', 'b']),
        eq($q, [$a, $b]),
    ]);
}));

var_dump(run_star(function ($q, $a, $b) {
    return conj_plus([
        disj_plus([
            eq([$a, $b], ['a', 'b']),
            eq([$a, $b], ['b', 'a']),
        ]),
        eq($q, [$a, $b]),
    ]);
}));
