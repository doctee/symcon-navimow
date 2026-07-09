# Navimow IP-Symcon Module

This directory is the installable distribution root for the Navimow module
scaffold developed by the SAEF Navimow case study.

The distribution is intentionally separate from the SAEF repository root.
IP-Symcon treats first-level repository directories as module candidates,
except for its reserved `libs` dependency directory.

Current scope:

- module metadata and lifecycle;
- account, device and configurator instances;
- variable profile and variable registration;
- supervised OAuth authorization-code exchange and token refresh;
- REST client boundary with discovery and status methods still gated;
- no active discovery, status polling, MQTT/WSS or mower commands.

Source engineering documentation:

```text
case-studies/navimow/
```
