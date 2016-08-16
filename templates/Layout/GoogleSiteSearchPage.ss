<h1>$Title<% if $Query %> / $Query<% end_if %></h1>

$GoogleSiteSearchForm

<% if $ResultCount %><p class="result-count">Showing $Results.Count of $ResultCount.Nice results</p><% end_if %>

<% if $Refinements %>
    <div class="refinements">
        <h3>Refine your search</h3>
        <ul>
            <% loop $Refinements %>
                <li><% if $active %><b>$anchor</b><% else %><a href="$link">$anchor</a><% end_if %></li>
            <% end_loop %>
        </ul>
    </div>
<% end_if %>

<% if $Results %>
    <div class="results">
        <ul>
            <% loop $Results %>
                <li>
                    <h2><a href="$link">$htmlTitle</a></h2>
                    <p>$htmlSnippet
                        <br><a href="$link">$htmlFormattedUrl</a>
                    </p>
                </li>
            <% end_loop %>
        </ul>

        <% if $PreviousPageLink || $NextPageLink %>
            <p class="pagination">
                <% if $PreviousPageLink %><a href="$PreviousPageLink">Previous page</a><% end_if %>
                <% if $NextPageLink %><a href="$NextPageLink">Next page</a><% end_if %>
            </p>
        <% end_if %>
    </div>
<% end_if %>
