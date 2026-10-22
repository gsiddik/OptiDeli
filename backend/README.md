# OptiDeli API

Laravel 13 API for the phase-one order and dispatch workflow. See the repository [README](../README.md) for installation, supported capabilities, tests and limitations.

Amounts use IDR minor units (one minor unit = Rp 0.01). Session auth and CSRF use the same origin as the React SPA. The application never reads tenant authority from a header supplied by the caller.
