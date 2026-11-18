#!/usr/bin/env bash
#
# Boots a throwaway Agent Memory server (SurrealDB + `spectrond dev`) for the
# PHP SDK's integration suite, and stops it again. CI runs exactly this.
#
#   scripts/agent-memory-server.sh start   # boot both, then write the client env file
#   scripts/agent-memory-server.sh stop    # stop both
#
# Then point the suite at it:
#
#   set -a; . .agent-memory/env; set +a
#   composer test:agent-memory-integration
#
# `start` bootstraps a fresh in-memory database with one context and a pinned
# admin key, so every run starts from the same empty state. If it fails, it
# prints the end of the server logs and stops whatever it started.
#
# Environment:
#   SPECTROND_BIN          spectrond binary (default: `spectrond` on PATH); build it from
#                          surrealdb/spectron with `cargo build --bin spectrond`
#   SURREAL_BIN            surreal binary (default: `surreal` on PATH)
#   SURREAL_PORT           SurrealDB port (default 18000)
#   AGENT_MEMORY_PORT      Agent Memory user API port (default 19090)
#   AGENT_MEMORY_CONTEXT   context id to bootstrap (default php_sdk)
#   AGENT_MEMORY_STATE_DIR logs, data, pid files and the env file (default .agent-memory).
#                          Must be new, empty, or one this script created earlier.
#   GEMINI_API_KEY         optional. With it the server gets a real embedder and LLM, and the
#                          env file enables the live tier (AGENT_MEMORY_LIVE=1). Without it
#                          the server runs on placeholder keys: everything except recall,
#                          context, forget, chat, and document ingestion works.

set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
STATE=${AGENT_MEMORY_STATE_DIR:-$ROOT/.agent-memory}
SPECTROND_BIN=${SPECTROND_BIN:-spectrond}
SURREAL_BIN=${SURREAL_BIN:-surreal}
SURREAL_PORT=${SURREAL_PORT:-18000}
PORT=${AGENT_MEMORY_PORT:-19090}
CONTEXT=${AGENT_MEMORY_CONTEXT:-php_sdk}
URL="http://127.0.0.1:${PORT}"
# Marks a state directory this script owns, so it never clears one it does not.
MARKER=.agent-memory-server
KEY=""

log() { printf '[agent-memory] %s\n' "$*" >&2; }

die() {
	log "error: $*"
	exit 1
}

# True when something already accepts connections on the port.
port_in_use() {
	(exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null
}

wait_for_free_port() { # wait_for_free_port PORT
	for _ in $(seq 1 10); do
		port_in_use "$1" || return 0
		sleep 1
	done
	die "port $1 is already in use; stop whatever listens there or choose another port"
}

# Polls a check until it passes, failing early if the process it waits on exits.
wait_until() { # wait_until SECONDS PIDFILE COMMAND...
	local seconds=$1 pid
	read -r pid _ <"$2"
	shift 2
	for _ in $(seq 1 "$seconds"); do
		"$@" && return 0
		kill -0 "$pid" 2>/dev/null || return 1
		sleep 1
	done
	return 1
}

http_ok() { curl -fsS -o /dev/null "$1" 2>/dev/null; }

# Probes an authenticated route. The key reaches curl on stdin, not in its argv.
key_authenticates() {
	printf 'header = "Authorization: Bearer %s"\n' "$KEY" \
		| curl -fsS -o /dev/null -K - "$URL/api/v1/$CONTEXT/me" 2>/dev/null
}

# Starts a server in the background and records its pid and the binary it
# runs, which `stop` checks before signalling.
launch() { # launch NAME LOGFILE BINARY COMMAND...
	local name=$1 logfile=$2 binary=$3
	shift 3
	"$@" >"$logfile" 2>&1 &
	echo "$! $binary" >"$STATE/$name.pid"
}

# Stops the process recorded for NAME, but only while it is still the one this
# script started: a stale pid may since have been reused by something else.
stop_one() { # stop_one NAME
	local pidfile="$STATE/$1.pid" pid binary
	[ -f "$pidfile" ] || return 0
	read -r pid binary <"$pidfile" || true
	if [ -n "${pid:-}" ] && ps -p "$pid" -o command= 2>/dev/null | grep -qF -- "${binary:-$1}"; then
		kill "$pid" 2>/dev/null || true
	fi
	rm -f "$pidfile"
}

stop() {
	[ -d "$STATE" ] || return 0
	stop_one spectrond
	stop_one surreal
}

dump_logs() {
	for file in "$STATE"/logs/*.log; do
		[ -f "$file" ] || continue
		log "--- last lines of $(basename "$file") ---"
		tail -n 40 "$file" >&2
	done
}

on_start_failure() {
	local code=$?
	log "start failed"
	dump_logs
	stop
	exit "$code"
}

prepare_state() {
	if [ -e "$STATE" ] && [ ! -f "$STATE/$MARKER" ] && [ -n "$(ls -A "$STATE" 2>/dev/null)" ]; then
		die "$STATE exists and was not created by this script; point AGENT_MEMORY_STATE_DIR at a new or empty directory"
	fi

	stop
	mkdir -p "$STATE"
	touch "$STATE/$MARKER"
	rm -rf "$STATE/logs" "$STATE/store"
	rm -f "$STATE/env"
	mkdir -p "$STATE/logs" "$STATE/store"
}

start() {
	command -v "$SPECTROND_BIN" >/dev/null 2>&1 || die "spectrond not found (set SPECTROND_BIN); build it from surrealdb/spectron with: cargo build --bin spectrond"
	command -v "$SURREAL_BIN" >/dev/null 2>&1 || die "surreal not found (set SURREAL_BIN); see https://surrealdb.com/install"

	prepare_state
	wait_for_free_port "$SURREAL_PORT"
	wait_for_free_port "$PORT"
	trap on_start_failure EXIT

	log "starting SurrealDB on :$SURREAL_PORT"
	launch surreal "$STATE/logs/surreal.log" "$SURREAL_BIN" \
		"$SURREAL_BIN" start --user root --pass root --bind "127.0.0.1:$SURREAL_PORT" memory
	wait_until 60 "$STATE/surreal.pid" http_ok "http://127.0.0.1:$SURREAL_PORT/health" \
		|| die "SurrealDB did not become ready"

	# A pinned, validly formatted key, so the bootstrap is reproducible and the
	# key is known before the server prints anything.
	KEY=$("$SPECTROND_BIN" generate-key php-sdk 2>/dev/null | grep -Eo 'sp-[A-Za-z0-9_-]+' | tail -n 1 || true)
	[ -n "$KEY" ] || die "spectrond generate-key produced no key"
	if [ "${GITHUB_ACTIONS:-}" = "true" ]; then
		echo "::add-mask::$KEY"
	fi

	# Keys travel by environment, never argv, so they stay out of `ps`.
	local live=0
	local -a server_env=(
		"SURREALDB_CONNECTION=ws://127.0.0.1:$SURREAL_PORT;root;root"
		"SPECTRON_BOOTSTRAP_API_KEY=$KEY"
		"SPECTRON_PROMETHEUS_BIND_ADDR="
	)
	if [ -n "${GEMINI_API_KEY:-}" ]; then
		live=1
		server_env+=("SPECTRON_EMBEDDINGS_API_KEY=$GEMINI_API_KEY" "SPECTRON_PROVIDER_GOOGLE_API_KEY=$GEMINI_API_KEY")
	else
		server_env+=("SPECTRON_EMBEDDINGS_API_KEY=placeholder-no-embeddings")
	fi

	log "starting spectrond dev on :$PORT (context $CONTEXT, live models: $([ $live = 1 ] && echo yes || echo no))"
	launch spectrond "$STATE/logs/spectrond.log" "$SPECTROND_BIN" \
		env "${server_env[@]}" "$SPECTROND_BIN" dev start \
		--object-store-url "file://$STATE/store" \
		--bind-address "127.0.0.1:$PORT" \
		--bootstrap --context-id "$CONTEXT"

	# The first boot applies every migration before it serves.
	wait_until 180 "$STATE/spectrond.pid" http_ok "$URL/api/v1/health" || die "spectrond did not become ready"
	wait_until 30 "$STATE/spectrond.pid" key_authenticates || die "the bootstrapped key does not authenticate"

	{
		echo "AGENT_MEMORY_ENDPOINT=$URL"
		echo "AGENT_MEMORY_CONTEXT=$CONTEXT"
		echo "AGENT_MEMORY_API_KEY=$KEY"
		if [ $live = 1 ]; then
			echo "AGENT_MEMORY_LIVE=1"
		fi
	} >"$STATE/env"

	trap - EXIT
	log "ready at $URL; client settings written to $STATE/env"
}

case "${1:-}" in
	start) start ;;
	stop) stop ;;
	*)
		echo "usage: $0 start|stop" >&2
		exit 2
		;;
esac
