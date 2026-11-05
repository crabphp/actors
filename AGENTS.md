# Agents

The framework's [AGENTS.md](https://github.com/crabphp/framework/blob/main/AGENTS.md) holds for this package: its rules for code, comments, tests and documentation all apply. What follows is particular to actors.

## Actors

- An actor is a class with `handle(Inbox $inbox, string $key)`; one runs per key and process at a time, as a fiber, and its memory is the variables of that call. The object is the container's and may be shared between keys. Nothing about an actor is stored: it is not durable, and the docs send what must last to runs or the database.
- Where an actor runs follows the broadcast carrier: with the local one, in the process that sends to it; with any other, in the process of the `actors` server that a hash of class and key picks, messages and replies travelling over the carrier. Do not add a second transport, a registry of where keys live, or a way to move them.
- Messages and replies are JSON, the first time too, and are delivered at most once. An inbox holds at most `actors.max_inbox` messages; past that, and to a stopping process, a message is dropped and logged and an ask for it refused. `ask()` always ends: by the reply, its timeout, its cancellation, or a refusal.
- An actor that returns or fails refuses the asks it took and did not reply to, and the messages it had not taken start its next run, unless it took none. There is no supervision beyond that.
