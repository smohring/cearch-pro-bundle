CearchPro
=========

* Enhances the default Contao search
* Uses the [Levenshtein algorithm](http://en.wikipedia.org/wiki/Levenshtein_distance)
* Ignores typing errors by showing alternate words e.g "Mannnheim" shows "Mannheim", "Räferenzn" shows "Referenzen"
* Shows "Did you mean..." on search results page
* Transliterates all special characters for example "o" = "ö", "ê" = "e"
* Added word stoplists for german and english to provide better search results 
* SearchIndexer now also indexes OpenGraph image tags.
* SearchIndexer saves transliterated Version of all words
* Shows thumbnail for each search result
* Search in all languages, not only the current selected
* Add multiple page roots to search for (default is one)

**Works on:**
Contao > 4.4.*

**HowTo:**
* Enable CearchPro in Contao search engine module
* Rebuild Search Index
* Use the included **mod_search.html5** to show the "Did you mean..." results
