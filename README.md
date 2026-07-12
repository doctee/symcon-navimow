# Navimow IP-Symcon Module

This repository is the installable distribution root for the Navimow module
developed by the SAEF Navimow case study.

Status: evidence-backed command-expanded private pilot / REST integration.

The module integrates Segway Navimow robotic mowers through the Navimow cloud
REST API. It is not an official Segway Navimow product.

## Scope

Implemented:

- account, configurator and device instances;
- supervised OAuth authorization-code setup;
- token refresh;
- mower discovery;
- read-only status refresh;
- battery, online and vehicle-state variables;
- Pause command with fresh Running precondition;
- Resume command with fresh Paused precondition;
- Dock command;
- bounded read-only Pause and Resume verification;
- long-running read-only Dock verification;
- restart-safe Dock verification without command replay;
- bounded token-refresh transport recovery.

Not implemented:

- Start;
- Stop;
- MQTT/WSS realtime updates;
- location or map data;
- Symcon Store packaging.

## Installation

Use this repository as an IP-Symcon module source:

```text
https://github.com/doctee/symcon-navimow.git
```

Recommended private-pilot procedure:

1. Add the repository in Symcon's module management.
2. Create a `Navimow Account` instance.
3. Configure the OAuth client settings.
4. Run the supervised OAuth authorization flow.
5. Create or use the `Navimow Configurator`.
6. Create a `Navimow Device` instance for the discovered mower.
7. Press `Refresh Status` and confirm that status values update.

For private Git installations, update through Symcon's module management after
new commits are published to the repository.

## OAuth Notes

The module currently expects a supervised authorization-code flow.

OAuth credentials are installation-specific. Do not publish or share:

- client secrets;
- authorization codes;
- access tokens;
- refresh tokens;
- raw OAuth callback URLs.

If authentication fails, re-run the supervised authorization flow before
testing commands.

## Safe Command Use

Pause, Resume and Dock are the only enabled mower commands.

Before pressing Pause, Resume or Dock:

- keep the mower and docking station in sight;
- keep the area clear;
- keep the official Navimow app available;
- be ready to use the physical stop control if needed.

Resume can begin mower movement and cutting immediately. Confirm that the
mower is visibly paused and that its movement path is clear before pressing
Resume.

The module sends one command per explicit user action and never retries that
write. Pause first requires a current Running status read. Resume first requires
a current Paused status read. After the cloud accepts a command, the module
verifies progress with read-only status calls using a command-specific bounded
deadline.

Expected command result flow:

```text
Accepted -> Pending Verification -> Verified
```

If the mower is already docked, the expected result is:

```text
Already In State
```

## Dock Verification

The Dock verification path treats `Docking` as valid progress.

Timing model:

- initial verification after about 5 seconds;
- read-only polling every 60 seconds while the mower is returning;
- maximum verification window of 15 minutes.

`Verification Timeout` means that `Docked` was not confirmed within the window.
It does not prove that the mower physically failed.

## Pilot Evidence

The command-expanded pilot build has passed:

- deterministic verification-timeout and final-deadline checks;
- deterministic temporary and continuous REST read-failure checks;
- deterministic token-refresh success, rejection and bounded retry checks;
- a supervised Symcon restart while Dock verification was active;
- passive scheduled token refresh with continued status polling;
- three supervised Dock transitions without duplicate command delivery;
- one supervised private and one direct Symcon Pause transition;
- one supervised private and one direct Symcon Resume transition;
- update compatibility checks preserving all eight public variable identities;
- archive continuity checks preserving all five operator-enabled logging streams.

Physical timeout and deliberate productive cloud failure were not induced.
Those failure paths are covered by deterministic no-network tests.

## Known Limitations

- The module uses an undocumented Navimow cloud API.
- Status is REST-polled and may lag behind the official app.
- Only one mower has direct live transition evidence in this case study.
- The repeated-operation evidence set is intentionally limited.
- OAuth client configuration remains installation-specific.
- Start and Stop remain deliberately disabled.
- MQTT/WSS realtime data is reserved for a later phase.
- The module is not approved for broad public release or the Symcon Store.

## Privacy

Do not share logs or payloads that contain:

- OAuth credentials or tokens;
- private device IDs;
- account identifiers;
- raw API payloads;
- garden, map or location data;
- private Symcon object IDs.

## Engineering Documentation

The engineering record for this MVP is maintained in:

```text
case-studies/navimow/
```

The command-expanded pilot release decision is documented in:

```text
case-studies/navimow/73-resume-integration-review-and-stop-readiness.md
```
