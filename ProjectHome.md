This project implements a google visualization datasource in PHP.

It has three main parts. The [datatable implementation](vistable.md) itself, the [query language parser](visparser.md), and the [formatters](visformat.md). The formatters should be (mostly) redundant in PHP 5.3 (or 5.2 with PECL intl) - but I dont have access to such a setup.

The implementation is fairly complete, and implements a [superset of the query language](visparser.md) (its also probably a subset in the sense that some things are doubtless broken!).

It has been (somewhat) tested using PHP 5.2.6

The code is still very rough - please file bugs!

If you wish to help out, contact me at mark\_vis@myosotissp.com
