/** 
 * =================================================================
 * Javascript code for OWASP CSRF Protector
 * Task: Fetch csrftoken from cookie, and attach it to every
 * 		POST request
 *		Allowed GET url
 * =================================================================
 */

var CSRFP = {
	/**
	 * Array of patterns of url, for which csrftoken need to be added
	 * In case of GET request also, provided from server
	 *
	 * @var string array
	 */
	checkForUrls: [<?php
		if (isset($_GET['param'])) {
			$patternArray = json_decode($_GET['param'],false);
			if ($patternArray) {
				foreach ($patternArray as $key => $value) {
					if ($key !== 0) echo ',';
					echo "'". $value ."'";
				}
			}
		}
	?>],
	/**
	 * Function to check if a certain url is allowed to perform the request
	 * With or without csrf token
	 * @param: string, url
	 * @return: boolean, 	true if csrftoken is not needed
	 * 						false if csrftoken is needed
	 */
	_isValidGetRequest: function(url) {
		for (var i = 0; i < CSRFP.checkForUrls.length; i++) {
			var match = CSRFP.checkForUrls[i].exec(url);
			if (match !== null && match.length > 0) {
				return false;
			}
		}
		return true;
	},
	/** 
	 * function to get Auth key from cookie Andreturn it to requesting function
	 * @param: void
	 * @return: string, csrftoken retrieved from cookie
	 */
	_getAuthKey: function() {
		var re = new RegExp("CSRF_AUTH_TOKEN=([^;]+)(;|$)");
		var RegExpArray = re.exec(document.cookie);
		
		if (RegExpArray === null) {
			//#todo: Action to take if CSRFtoken not found
			return false;
		}
		return RegExpArray[1];
	},
	/** 
	 * Function to get domain of any url
	 * @param: string, url
	 * @return: string, domain of url
	 */
	_getDomain: function(url) {
		if (url.indexOf("http://") !== 0 && url.indexOf("https://") !== 0)
			return document.domain;
		return /http(s)?:\/\/([^\/]+)/.exec(url)[2];
	},
	/**
	 * Function to create and return a hidden input element
	 * For stroing the CSRFP_TOKEN
	 *
	 * @param void
	 * @return input element
	 */
	_getInputElt: function() {
		var hiddenObj = document.createElement("input");
		hiddenObj.name = 'csrfp_token';
		hiddenObj.type = 'hidden';
		hiddenObj.value = CSRFP._getAuthKey();
		return hiddenObj;
	},
	/** 
	 * Remove jcsrfp-token run fun and then put them back 
	 *
	 * @param function
	 * @param reference form obj
	 *
	 * @retrun function
	 */
	_csrfpWrap: function(fun, obj) {
		return function(event) {
			// Remove CSRf token if exists
			if (typeof obj.csrfp_token !== 'undefined') {
				var target = obj.csrfp_token;
				target.parentNode.removeChild(target);
			}
			
			// Trigger the functions
			var result = fun.apply(this, [event]);
			
			// Now append the csrftoken back
			obj.appendChild(CSRFP._getInputElt());
			
			return result;
		};
	}
	/**
	 * Initialises the CSRFProtector js script
	 *
	 * @param void
	 * @return void
	 */
	_init: function() {
		//convert these rules received from php lib to regex objects
		for (var i = 0; i < CSRFP.checkForUrls.length; i++) {
			CSRFP.checkForUrls[i] = CSRFP.checkForUrls[i].replace(/\*/g, '(.*)')
								.replace(/\//g, "\\/");
			CSRFP.checkForUrls[i] = new RegExp(CSRFP.checkForUrls[i]);
		}
	
	}
	
}; 

//==========================================================
// Adding tokens, wrappers on window onload
//==========================================================

window.onload = function() {
	
	// Call the init funcion
	CSRFP._init();
	
	//==================================================================
	// Adding csrftoken to request resulting from <form> submissions
	// Add for each POST, while for mentioned GET request
	//==================================================================
	for(var i = 0; i < document.forms.length; i++) {
		document.forms[i].addEventListener("submit", function(event) {
			if (typeof event.target.csrfp_token === 'undefined') {
				event.target.appendChild(CSRFP._getInputElt());
			} else {
				//modify token to latest value
				event.target.csrfp_token.value = CSRFP._getAuthKey();
			}
		});
	}
	
	/**
	 * Add wrapper for HTMLFormElements addEventListener so that any further 
	 * addEventListens won't have trouble with CSRF token
	 */
	HTMLFormElement.prototype.addEventListener_ = HTMLFormElement.prototype.addEventListener;
	HTMLFormElement.prototype.addEventListener = function(eventType, fun, bubble) {
		if (eventType === 'submit') {
			var wrapped = CSRFP._csrfpWrap(fun, this);
			this.addEventListener_(eventType, wrapped, bubble);
		} else {
			this.addEventListener_(eventType, fun, bubble);
		}	
	}


	//==================================================================
	// Wrapper for XMLHttpRequest
	// Set X-No-CSRF to true before sending if request method is 
	//==================================================================

	/** 
	 * Wrapper to XHR open method
	 * Add a property method to XMLHttpRequst class
	 * @param: all parameters to XHR open method
	 * @return: object returned by default, XHR open method
	 */
	function new_open(method, url, async, username, password) {
		this.method = method;

		if (method.toLowerCase() === 'get' && !CSRFP._isValidGetRequest(url)) {
			//modify the url
			if (url.indexOf('?') === -1) {
				url += "?csrfp_token=" +CSRFP._getAuthKey();
			} else {
				url += "&csrfp_token" +CSRFP._getAuthKey();
			}
		}

		return this.old_open(method, url, async, username, password);
	}

	/** 
	 * Wrapper to XHR send method
	 * Add query paramter to XHR object
	 * @param: all parameters to XHR send method
	 * @return: object returned by default, XHR send method
	 */
	function new_send(data) {
		if (this.method.toLowerCase() === 'post') {
			
			//#needDiscussion: whats the utility, was used in paper by Riccardo
			this.setRequestHeader("X-No-CSRF", "true");
			
			if (data !== undefined) {
				data += "&";
			} else {
				data = "";
			}
			
			data += "csrfp_token=" +CSRFP._getAuthKey();
		}
		return this.old_send(data);
	}

	//wrappig
	XMLHttpRequest.prototype.old_send = XMLHttpRequest.prototype.send;
	XMLHttpRequest.prototype.old_open = XMLHttpRequest.prototype.open;
	XMLHttpRequest.prototype.open = new_open;
	XMLHttpRequest.prototype.send = new_send;

	//==================================================================
	// Rewrite existing urls ( Attach CSRF token )
	// Rules:
	// Rewrite those urls which matches the regex sent by Server
	// Ingore cross origin urls & internal links (one with hashtags)
	// Append the token to those url already containig GET query parameter(s)
	// Add the token to those which does not contain GET query parameter(s)
	//==================================================================

	for (var i = 0; i < document.links.length; i++) {
        document.links[i].addEventListener("mousedown", function(event) {
            var urlDisect = event.target.href.split('#');
            var url = urlDisect[0];
            var hash = urlDisect[1];
			
            if(CSRFP._getDomain(url).indexOf(document.domain) === -1
				|| CSRFP._isValidGetRequest(url)) {
                //cross origin -- ignore or not to be protected by rules
				return;
            }
            
            if (url.indexOf('?') !== -1) {
                if(url.indexOf('csrfp_token') === -1) {
                    url += "&csrfp_token=" +CSRFP._getAuthKey();
                } else {
                    url = url.replace(new RegExp("csrfp_token=.*?(&|$)", 'g'), "csrfp_token=" +CSRFP._getAuthKey() + "$1");
                }
            } else {
                url += "?csrfp_token=" +CSRFP._getAuthKey();
            }
            
            event.target.href = url;
            if (hash !== undefined) {
                event.target.href += '#' +hash;
            }
        });
	}

}