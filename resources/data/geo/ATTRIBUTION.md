# Geographic data attribution

`cities5000.tsv.gz` is derived from the **GeoNames** gazetteer (https://www.geonames.org/),
populated places with 5,000 or more inhabitants. GeoNames data is licensed under
**Creative Commons Attribution 4.0** (https://creativecommons.org/licenses/by/4.0/).

The extract keeps only: GeoNames id, name, country, admin-1 code, latitude, longitude
and population, as redistributed in the `geonamescache` package (MIT-licensed code,
GeoNames data). It is a 2020 snapshot — fine for naming neighbouring communities,
not an authority on current boundaries or populations.

It is used only to suggest nearby places to a business owner, who reviews and edits
every suggestion. It is never sent to a third party.
