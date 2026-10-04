# UnoPIM Additional Props Editor

A proposed open-source extension that makes flexible product data in UnoPIM's
`additional` JSON field visible and editable in the admin.

**Status: requirements and design only. No installable extension is available
yet.** This repository describes the intended behavior; it does not claim that
the features below have been implemented or tested.

## The need

UnoPIM's native product editor is driven by predefined attributes and attribute
families. Products also expose an `additional` JSON field through the REST API,
but the standard product editor in the reviewed v3.1.3 source does not provide a
viewer or editor for that field.

That leaves a gap for product information that does not fit a predefined schema:

- Open-ended specifications expressed as name–value pairs.
- Feature bullets expressed as an ordered array of strings.
- Extra facts that complement, rather than replace, a product's native attributes.

Data can be retained programmatically without being accessible to the person
reviewing the product. This extension aims to close that gap with ordinary form
controls, not a requirement for users to edit raw JSON.

## Initial scope

Add an **Additional Product Data** panel to the existing product-editing page.

| Section | Human interface | Product storage |
| --- | --- | --- |
| Additional specifications | Add, edit, and remove name–value rows | `additional.attributes` |
| Features | Add, edit, remove, and reorder feature bullets | `additional.features` |

The same panel should work for products whose specifications are entirely in
`additional` and for products that use native attributes with only their excess
specifications in `additional`. Native fields remain in their existing editor
sections. No organization-, vendor-, category-, or product-specific rules belong
in the extension.

## Proposed data contract

The following is a product payload fragment, not a new API endpoint:

```json
{
  "additional": {
    "attributes": {
      "Material": "Steel",
      "Overall length": "200 mm"
    },
    "features": [
      "Comfortable grip",
      "Corrosion-resistant finish"
    ],
    "metadata": {
      "source_reference": "example-123"
    }
  }
}
```

The initial editor manages only `attributes` and `features`. `metadata` above
illustrates unrelated data that must be preserved; it is not a required field.

- Specifications initially use string names and string values, preserving
  unit-bearing wording without guessing conversions or semantic types.
- Feature values remain an ordered JSON array of strings, not HTML, a serialized
  string, or predefined multiselect options.
- Missing managed sections should be usable as empty forms.
- Existing unsupported shapes or non-string values must not be silently
  converted, discarded, or replaced. Present a clear limitation and preserve them.
- Specification names must be unique within the managed object. Reject
  duplicates rather than silently losing a row during serialization.
- Opening a product must not mutate its data.

The native `values` payload remains separate. Editing additional data must not
change the product's native attributes, family, categories, or publication status.

## Integration and safety requirements

- Implement a self-contained UnoPIM/Laravel package using documented extension
  mechanisms. Prefer existing product-editor view-render events over replacing
  core templates or maintaining a UnoPIM fork.
- Use the existing admin session and product permissions. Enforce authorization
  and CSRF protection on writes, not merely by hiding buttons.
- Validate incoming data and render user-supplied content safely as text.
- Save only the managed sections, preserving unrelated `additional` keys.
- Detect conflicting edits rather than silently overwriting newer data from
  another user or integration. A blind replacement of a stale complete
  `additional` object is not acceptable.
- Integrate changes with UnoPIM's history mechanisms where supported, verifying
  attribution and the visibility of changes rather than assuming JSON edits are
  automatically covered.
- Keep the stored representation readable by existing API clients. The UI must
  not introduce a second, competing copy of the product's additional data.

UI injection and persistence are separate responsibilities: adding a Blade view
alone does not make its inputs part of UnoPIM's native save behavior.

## Boundaries

This is an editor for additional product data, not an enrichment engine or a
general-purpose schema builder. The initial scope does not include AI generation,
connectors, automatic attribute creation, or automatic promotion of additional
properties into native attributes.

Human-editable JSON does **not** automatically gain native attribute filtering,
completeness rules, channel/locale scoping, or variant inheritance. Continue to
use native attributes when those capabilities are required. Any extension of
those semantics needs its own explicit design and verification.

## Compatibility and development

The initial research target is **UnoPIM 3.1.3**. This is a development target,
not a claim of extension compatibility. Supported versions, installation steps,
asset deployment, upgrades, and removal behavior will be documented once there
is an implementation and corresponding test evidence.

Development should begin with tests for the data contract and authorized save
behavior, followed by a small, working admin panel. Acceptance requires both
server-side verification and actual browser testing:

1. Existing specifications and feature strings appear on the product page.
2. Add/edit/remove operations persist after saving and reloading; feature order
   survives a fresh API read.
3. Both additional-only and hybrid products work without changing native fields.
4. Unrelated JSON, unsupported existing values, and unchanged data are preserved.
5. Invalid input, insufficient permissions, and conflicting edits fail clearly
   without data loss.
6. Empty and incompatible payloads have understandable behavior.
7. History behavior is verified and any limitations are documented.

Tests and examples should use synthetic, generic product data.

## Upstream references

- [Product REST API](https://devdocs.unopim.com/3.1/api/product.html)
- [Package development](https://devdocs.unopim.com/3.1/packages/)
- [View-render events](https://devdocs.unopim.com/3.1/advanced/render-event.html)
- [Access control](https://devdocs.unopim.com/3.1/packages/create-acl.html)
- [Package testing](https://devdocs.unopim.com/3.1/packages/testing.html)

This is an independent community project, not an official UnoPIM extension.

## License

[MIT](LICENSE).
