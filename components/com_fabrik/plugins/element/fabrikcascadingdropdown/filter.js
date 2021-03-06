 var CascadeFilter = new Class({
		initialize: function(observerid, opts) {
			this.options = opts;
			this.observer = $(observerid);
			if(this.observer) {
				new Element('img', {'id':this.options.filterid + '_loading','src':this.options.liveSite + 'media/com_fabrik/images/ajax-loader.gif', 'alt':'loading...', 'styles':{'opacity':'0'}}).injectBefore(this.observer);
				var v = this.observer.get('value');
				var url = this.options.liveSite + 'index.php?option=com_fabrik&format=raw&controller=plugin&task=pluginAjax&plugin=fabrikcascadingdropdown&method=ajax_getOptions&element_id='+this.options.elid;
				this.myAjax = new Ajax(url, {method:'post',
					'data':{'v':v, 'formid':this.options.formid, 'fabrik_cascade_ajax_update':1, 'filterview':'table'},
					onComplete: this.ajaxComplete.bindAsEventListener(this)
				});
	
				this.observer.addEvent('change', function() {
					this.periodcount = 0;
					$(this.options.filterid + '_loading').setStyle('opacity','1');
					var v = this.observer.get('value');
					this.myAjax.options.data.v = v;
					// $$$ hugh - added this so we fake out submitted form data for use as placeholders in query filter
					$filterData = eval(this.options.filterobj).getFilterData();
					$extend(this.myAjax.options.data, $filterData);
					this.myAjax.request();
				}.bind(this));
				
				var v = this.observer.get('value');
				this.periodical = this.update.periodical(500, this);
				this.periodcount = 0;
			}else{
				fconsole('observer not found ', observerid);
			}
		},
		
		update:function() {
			if (this.observer) {
				this.myAjax.options.data.v = this.observer.get('value');
				// $$$ hugh - added this so we fake out submitted form data for use as placeholders in query filter
				$filterData = eval(this.options.filterobj).getFilterData();
				$extend(this.myAjax.options.data, $filterData);
				this.myAjax.request();
			}
		},
		
		ajaxComplete:function(json) {
			json = Json.evaluate(json);
			this.periodcount ++;
			if (this.periodcount > 5) {
				this.endAjax();
				return;
			}
			if($type($(this.options.filterid)) === false) {
				fconsole('filterid not found: ', this.options.filterid);
				this.endAjax();
				return;				
			}

			$(this.options.filterid).empty();
			json.each(function(item) {
				new Element('option', {'value':item.value}).appendText(item.text).injectInside($(this.options.filterid));
				$(this.options.filterid).value = this.options.def;
			}.bind(this));
			if(json.length > 0) {
				if((json.length == 1 && json[0].value == '') === false) {
					this.endAjax();
				}
			} else {
				this.endAjax();
			}
		},
		
		endAjax:function()
		{
			$(this.options.filterid + '_loading').setStyle('opacity','0');
			$clear(this.periodical);
		}
});