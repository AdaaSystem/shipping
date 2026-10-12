# Changelog

## [Unreleased]

- Initial scaffold: framework-agnostic core (Contracts, DTOs, Enums, StatusNormalizer, CarrierRegistry, ShippingManager, FakeDriver) + Laravel service provider stub + Pest test skeleton.
- Native Bosta driver (`src/Drivers/Bosta/`: BostaClient, BostaDriver, BostaPayloadFactory, DeliveryType) with create/track/label/cancel, return (CRP 25) + exchange (30) via create payload, COD + webhooks, raw `Authorization` API-key auth, sandbox/production hosts, and Pest coverage via injectable `Http\Factory::fake()`.
