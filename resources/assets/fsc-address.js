/* Fullstack Content: address autocomplete for <x-content::address-field>. Plain JavaScript, no dependencies.
   The component renders the manual fields (they work without this script). This script turns the block into one search field with
   suggestions (ArrowUp/ArrowDown/Enter/Escape), a compact summary with "Change", and the manual fields as the fallback.
   It only talks to this site's own address routes; nothing is stored and nothing leaves the page except those requests. */
(function () {
    "use strict";

    var DEBOUNCE_MS = 250;
    var MIN_CHARS = 3;
    var POSTCODE = /^[1-9][0-9]{3}\s?[A-Za-z]{2}$/;

    function init(root) {
        if (root.getAttribute("data-fsc-ready") === "1") {
            return;
        }

        var config;

        try {
            config = JSON.parse(root.getAttribute("data-fsc-config") || "{}");
        } catch (error) {
            return;
        }

        var labels = config.labels || {};
        var input = root.querySelector("[data-fsc-q]");
        var list = root.querySelector("[data-fsc-list]");
        var status = root.querySelector("[data-fsc-status]");
        var search = root.querySelector("[data-fsc-search]");
        var summary = root.querySelector("[data-fsc-summary]");
        var summaryText = root.querySelector("[data-fsc-summary-text]");
        var manual = root.querySelector("[data-fsc-manual-fields]");
        var fields = {};
        var requiredKeys = ["street", "houseNumber", "postcode", "city"];

        Array.prototype.forEach.call(root.querySelectorAll("[data-fsc-key]"), function (el) {
            fields[el.getAttribute("data-fsc-key")] = el;
        });

        if (!input || !list || !manual || !fields.street || !fields.postcode) {
            return;
        }

        root.setAttribute("data-fsc-ready", "1");
        root.classList.remove("fsc-address--nojs");

        var items = [];
        var active = -1;
        var timer = null;
        var controller = null;
        var mode = "search";
        var lookupController = null;

        function text(key, values) {
            var out = String(labels[key] || "");

            Object.keys(values || {}).forEach(function (name) {
                out = out.replace("{" + name + "}", values[name]);
            });

            return out;
        }

        function setRequired(on) {
            requiredKeys.forEach(function (key) {
                if (fields[key]) {
                    fields[key].required = !!(on && config.required);
                }
            });
        }

        function closeList() {
            list.hidden = true;
            list.innerHTML = "";
            items = [];
            active = -1;
            input.setAttribute("aria-expanded", "false");
            input.removeAttribute("aria-activedescendant");
        }

        function show(newMode) {
            mode = newMode;
            search.hidden = newMode !== "search";
            summary.hidden = newMode !== "summary";
            manual.hidden = newMode !== "manual";
            input.required = newMode === "search" && !!config.required && !fields.street.value;
            setRequired(newMode === "manual");

            if (newMode !== "search") {
                closeList();
            }
        }

        function summaryLine() {
            var number = (fields.houseNumber.value || "") + (fields.addition && fields.addition.value ? " " + fields.addition.value : "");

            return (fields.street.value + " " + number).trim() + ", " + fields.postcode.value + " " + fields.city.value;
        }

        function set(key, value) {
            if (fields[key]) {
                fields[key].value = value == null ? "" : String(value);
            }
        }

        function choose(address) {
            set("street", address.street);
            set("houseNumber", address.houseNumber);
            set("addition", [address.houseLetter, address.addition].filter(Boolean).join("-"));
            set("postcode", address.postcode);
            set("city", address.city);
            set("country", address.country || "NL");
            set("lat", address.lat);
            set("lon", address.lon);
            input.value = "";
            closeList();
            summaryText.textContent = summaryLine();
            show("summary");
            status.textContent = text("selected", { address: summaryLine() });
            fields.street.dispatchEvent(new Event("change", { bubbles: true }));
        }

        function render(addresses) {
            list.innerHTML = "";
            items = addresses;
            active = -1;

            if (addresses.length === 0) {
                var empty = document.createElement("li");
                empty.className = "fsc-address__empty";
                empty.textContent = text("noResults");
                list.appendChild(empty);
                status.textContent = text("noResults");
            } else {
                addresses.forEach(function (address, index) {
                    var li = document.createElement("li");
                    li.id = root.id + "-opt-" + index;
                    li.className = "fsc-address__option";
                    li.setAttribute("role", "option");
                    li.setAttribute("aria-selected", "false");
                    li.textContent = address.label;
                    // mousedown, not click: it fires before the input loses focus (blur closes the list).
                    li.addEventListener("mousedown", function (event) {
                        event.preventDefault();
                        choose(address);
                    });
                    list.appendChild(li);
                });

                status.textContent = text("results", { count: addresses.length });
            }

            list.hidden = false;
            input.setAttribute("aria-expanded", "true");
        }

        function highlight(index) {
            var options = list.querySelectorAll("[role=option]");

            Array.prototype.forEach.call(options, function (el, i) {
                el.setAttribute("aria-selected", i === index ? "true" : "false");
                el.classList.toggle("is-active", i === index);
            });

            active = index;

            if (index >= 0 && options[index]) {
                input.setAttribute("aria-activedescendant", options[index].id);
                options[index].scrollIntoView({ block: "nearest" });
            } else {
                input.removeAttribute("aria-activedescendant");
            }
        }

        function request(url, signal) {
            return fetch(url, { headers: { Accept: "application/json" }, credentials: "omit", signal: signal }).then(function (response) {
                return response.ok ? response.json() : null;
            });
        }

        function suggest(query) {
            if (controller) {
                controller.abort();
            }

            controller = typeof AbortController === "function" ? new AbortController() : null;

            request(config.suggestUrl + "?limit=8&q=" + encodeURIComponent(query), controller ? controller.signal : undefined)
                .then(function (data) {
                    if (input.value.trim() !== query) {
                        return;
                    }

                    render(data && Array.isArray(data.addresses) ? data.addresses.slice(0, 8) : []);
                })
                .catch(function () {
                    // Aborted or offline: keep what is shown; the manual fields stay available.
                });
        }

        input.addEventListener("input", function () {
            window.clearTimeout(timer);
            var query = input.value.trim();

            if (query.length < MIN_CHARS) {
                closeList();

                return;
            }

            timer = window.setTimeout(function () {
                suggest(query);
            }, DEBOUNCE_MS);
        });

        input.addEventListener("keydown", function (event) {
            var open = !list.hidden && items.length > 0;

            if (event.key === "ArrowDown" && open) {
                event.preventDefault();
                highlight(active + 1 >= items.length ? 0 : active + 1);
            } else if (event.key === "ArrowUp" && open) {
                event.preventDefault();
                highlight(active <= 0 ? items.length - 1 : active - 1);
            } else if (event.key === "Enter" && open && active >= 0) {
                event.preventDefault();
                choose(items[active]);
            } else if (event.key === "Escape" && !list.hidden) {
                event.preventDefault();
                closeList();
            }
        });

        input.addEventListener("blur", function () {
            // A short delay lets a tap on an option register first.
            window.setTimeout(closeList, 120);
        });

        root.querySelector("[data-fsc-manual]").addEventListener("click", function () {
            show("manual");
            fields.street.focus();
        });

        root.querySelector("[data-fsc-change]").addEventListener("click", function () {
            show("search");
            input.focus();
        });

        // Manual mode: a complete postcode + house number fills an empty street and city.
        function lookup() {
            var postcode = fields.postcode.value.trim();
            var number = (fields.houseNumber.value + (fields.addition ? fields.addition.value : "")).trim();

            if (!POSTCODE.test(postcode) || !/^[1-9][0-9]{0,4}/.test(number) || (fields.street.value && fields.city.value)) {
                return;
            }

            if (lookupController) {
                lookupController.abort();
            }

            lookupController = typeof AbortController === "function" ? new AbortController() : null;

            request(config.lookupUrl + "?postcode=" + encodeURIComponent(postcode) + "&number=" + encodeURIComponent(number), lookupController ? lookupController.signal : undefined)
                .then(function (data) {
                    var address = data && data.address;

                    if (!address || mode !== "manual") {
                        return;
                    }

                    if (!fields.street.value) {
                        set("street", address.street);
                    }

                    if (!fields.city.value) {
                        set("city", address.city);
                    }

                    set("lat", address.lat);
                    set("lon", address.lon);
                })
                .catch(function () {
                    // Offline or aborted: the visitor simply types the rest.
                });
        }

        ["postcode", "houseNumber", "addition"].forEach(function (key) {
            if (fields[key]) {
                fields[key].addEventListener("change", lookup);
            }
        });

        // Start state: a prefilled address shows as the summary, otherwise the search field.
        if (root.getAttribute("data-fsc-filled") === "1") {
            summaryText.textContent = summaryLine();
            show("summary");
        } else {
            show("search");
        }
    }

    function initAll() {
        Array.prototype.forEach.call(document.querySelectorAll("[data-fsc-address]"), init);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initAll);
    } else {
        initAll();
    }

    window.fscAddress = { init: initAll };
})();
