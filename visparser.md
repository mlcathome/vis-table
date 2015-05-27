# Introduction #

This class is used internally to parse a query (the tq param).


# Details #

[vistable](vistable.md) supports a superset of the google query language:
  * aggregation functions can be applied to any column expression (not just ids)
  * aggregation functions can appear in the where clause
  * non-aggregated, non-grouped column expressions can appear in the select clause

so eg `select user_name,max(distance/time) where min(distance)>10000 and distance < 20000 group by user_id` is a valid query.

Note that here it is assumed that two rows having the same user\_id will have the same user\_name. If not, the grouped row will get some randomly chosen user\_name from all the user\_names which appear in the group.

When aggregation functions appear in the where clause, visquery looks at all the "and" terms in the expression, and each term is assigned either to the "where" clause (if it contains no aggregation functions) or to the "having" (sql terminology) clause (if it does).

So the above, in sql, would be `select user_name,max(distance/time) where distance < 20000 group by user_id having min(distance)>10000`.

Obviously, its possible to write queries that dont fit into this - eg:
`where min(distance)/time` contains both an aggregated expression and a non-aggregated expression, which cant be separated into to "and" conditions. In that case, you will get strange results (unless of course, time has the same value for all elements of the group).