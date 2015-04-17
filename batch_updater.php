<?php 
/**
* @class BatchUpdater
*
* A tiny tool with mimimal external dependencies that
* updates the wp_user meta table in a general way
* it can be hooked into in a pretty general manner.
* (this general manner is depicted at the bottom of this file.)
*/
class BatchUpdaterForm
{
//<Singleton> Implementation
	private static $form = null;
	static function Instance()
	{
		if( BatchUpdaterForm::$form==null)
			BatchUpdaterForm::$form = new BatchUpdaterForm();
		return BatchUpdaterForm::$form;
	}
//</Singleton> 

	private function __construct()
	{

		$this->profiles = array();
		$this->page_name = "batch-cache-submenu-page";
		$this->ajax_name = "gdrp_batch_updater";
		global $wpdb;

		add_action( 'admin_menu', array( $this, 'add_pages' ) );
		add_action( 'admin_footer', array( $this, 'ajax_js' )); // Write our JS below here
		
		add_action( 'wp_ajax_'.$this->ajax_name , array( $this, 'ajax_request' ) );
		//add_action( 'wp_ajax_nopriv_gdrp_batch_updater', array( $this, 'ajax_request' ) );

		
	}

	function add_pages()
	{
		add_submenu_page( 'tools.php', 'Batch Cache', 'Meta Cache', 'manage_options', $this->page_name, array( $this, 'echo_batch_cache_form') );
	}

	
	function register($select_sql,$batch_size,$query_name,$update_function,$display_name,$count_sql)
	{
		$this->profiles [$query_name] = 
			array(
			"select_sql" => $select_sql,
			"batch_size" => $batch_size,
			"query_name" => $query_name,
			"display_name" => $display_name,
			"update_function" => $update_function,
			"select_sql_count" => $count_sql);		
	}

	function echo_batch_cache_form() 
	{
		foreach ($this->profiles as $profile)
		{
			$bu = new BatchMetaUpdater
				   ($profile ['select_sql'],
					$profile['batch_size'],
					$profile['query_name'],
					$profile ['update_function'],
					$profile ['select_sql_count'] );
			
			?>
			<div class="wrap"><div id="icon-tools" class="icon32 <?php  echo $profile['query_name']; ?>_container"></div>
				<h2><?php echo $profile['display_name']; ?></h2>
				<button id='<?php  echo $profile['query_name']; ?>' class='ajax_database_runner' value="refresh" >Start Process</button>
				<div class="progress">
				  <div class="progress-bar" role="progressbar" aria-valuenow="70"
				  aria-valuemin="0" aria-valuemax="100" style="width:70%">
					<span class="sr-only"><label class="percent_<?php  echo $profile['query_name']; ?>"><?php  echo $bu->percent_complete(); ?></label>% Complete</span><i style="display:none" class="<?php  echo $profile['query_name']; ?>_spinner fa fa-spinner"> ..processing</i>
				  </div>
				</div>			
			</div>
			<?php
		}
	}

	/**
	* ajax_request() process a batch of database updates.
	* @return an array(int,int) depicting successes:failues
	*/
	function ajax_request() 
	{

		if ( isset($_REQUEST) && isset($_REQUEST['query_id']) ) 
		{
			global $wpdb;
			$profile = null;
			if(isset($this->profiles[$_REQUEST['query_id']]))
				$profile =$this->profiles[$_REQUEST['query_id']];
			if($profile == null)
				return;
			$bu = new BatchMetaUpdater
				   ($profile ['select_sql'],
					$profile['batch_size'],
					$profile['query_name'],
					$profile ['update_function'],
					$profile ['select_sql_count'] );
			$sucvsfail = $bu->run();
			
			echo json_encode($sucvsfail );
			die();
		}
		else
		{
			echo json_encode(array("message" => "No Valid Query Id"));
			die();
		}

	   die();
	}


	function ajax_js()
	{
		?>
		<script>
		jQuery(document).ready(function($) 
		{
			$(".ajax_database_runner").click(function()
			{	
				var query_id_str = $(".ajax_database_runner").attr("id");
				
				var button = $(this);
				if(button.attr("processing") == "1")
				{
					button.attr("processing","0");
					button.html("Start Processing");
					$("."+query_id_str+"_spinner").hide();	
					return;
				}
				button.attr("processing","1");
				$("."+query_id_str+"_spinner").show();
				button.html("Halt");
					
				grabAjaxData(button,query_id_str);
					
			});
		});

		function grabAjaxData(button,query_id_str)
		{
				jQuery.ajax({
					type: "POST",
					url: ajaxurl,
					data: {
						action: "gdrp_batch_updater",
						query_id: query_id_str
					},
					error: function(response) 
					{
						alert("Halted due to comm error.");
						button.attr("processing","0");
						jQuery("."+query_id_str+"_spinner").hide();
					},
					success: function(response) 
					{
						jQuery(".percent_"+query_id_str).html(response.percent);
						if(response.percent < 100 && button.attr("processing") == "1")
						{
							grabAjaxData(button,query_id_str);
						}
					},
					dataType: "json"	
				});		
		}
		</script>	
		<?php
	}

}

// Make Magic Happen
BatchUpdaterForm::Instance();


/**
* @class BatchMetaUpdater
*
* updates the wp_user meta table in a general way
*/
class BatchMetaUpdater
{
	/**
	* BatchMetaUpdater($select_id_sql,$batch_size,$query_name,$update_function)
	*
	* @param string				$select_id_sql
	* @param int				$batch_size
	* @param callback			$query_name
	* @param wordpress_callback $update_function
	* 
	* create an "updater", that will use $update_function on the list of $select_id_sql user_meta.
	* user_ids, all while minding $batch_size. conflicts are avoided by 
	* setting $query_name to a unique value.
	*/
	function __construct($select_id_sql,$batch_size,$query_name,$update_function,$count_sql )
	{
		$this->row_count = 1; 
		$this->limit = 0;
		$this->batch = $batch_size;
		$this->name = $query_name;
		$this->started = false;
		$this->count_sql ="";
		$this->user_update_query = $update_function ;

		$this->id_sql = $this->prepare_id_query($select_id_sql,$count_sql); 
	}


	/**
	* run() the BatchMetaUpdater for a batch of id supplied
	* @return an array(int,int) depicting successes:failues
	*/
	function run()
	{
		global $wpdb;

		if($this->user_update_query  == null)
			return;
		if($this->started != false)
			return;

		set_transient($this->name."-limit",($this->limit + $this->batch), 10 *HOUR_IN_SECONDS);
		$ostensive_user_ids = $wpdb->get_results($this->id_sql);
		$failures = 0;
		$successes = 0;
		foreach($ostensive_user_ids  as $row_with_id)
		{
			$bool = call_user_func_array ( $this->user_update_query , array(reset($row_with_id)));
			if($bool == false || !isset($bool) )
				$failures ++;
			else
				$successes ++;
		}
		return array("success"		=> $successes, 
					 "fail"			=> $failures, 
					 "rows_total"	=> $this->row_count,
					 "index"		=> $this->limit,
					 "percent"		=> $this->percent_complete()); 
	}

	function percent_complete()
	{
		if($this->row_count ==0)
			return "undefined";
		return round($this->limit /$this->row_count,4)*100;
	}


	/**
	* prepare_id_query($select_sql) 
	*
	* $select_sql array(int) select ids for use with the update functions
	* 
	* Prepare the sql function by addining a needed limit clause
	*/
	private function prepare_id_query($select_sql,$count_sql)
	{
		$this->row_count = get_transient($this->name."-count");
		$start_point = get_transient($this->name."-limit");
		if($start_point ==false)
			set_transient($this->name."-limit",0, 1 *HOUR_IN_SECONDS);

		if($this->row_count ==false )
		{
			global $wpdb;
			$count = $wpdb->get_row($count_sql);
			$count = reset($count);
			set_transient($this->name."-count",$count, 1 *HOUR_IN_SECONDS);
			$this->row_count = $count;
		}

		$this->limit = $start_point;
		$select_sql.= " LIMIT ".$start_point.",".$this->batch ." " ;
		return $select_sql;
	}
}

// A short usage demo. Change everyones name to Carrot Top : 


	///
	// Register an update mechanism on the back end, 
	// which processes bulk user donation-total updates.
	///
	function register_carrot_top_update()
	{
		// This is ALL the code that is required to register 
		// a custom cache update mechanism. 
		// Basically, all the batch update needs, testing and live,
		// can now be dispached in the following manner:
		global $wpdb;
		$profile = array(
			"select_sql" => "select ID from {$wpdb->prefix}users", // Where the IDS come from
			"select_sql_count" => "select count(ID) from {$wpdb->prefix}users", // How we calculate a count
			"batch_size" => 100, //How many ids to "update" at ince
			"query_name" => "carrot_top", // A unique name
			"display_name" => "Carrot Top Update", //What to display this as, on the admin panel
			"update_function" => "update_carrot");	//Custom update function, passed an ID
		
		// Registration
		BatchUpdaterForm::Instance()->register($profile["select_sql"],
						$profile["batch_size"] ,
						$profile["query_name"] ,
						$profile["update_function"],
						$profile["display_name"],
						$profile["select_sql_count"]);
	}

	///
	// update the cached donation totals for a certain user
	///
	function update_carrot($user_id)
	{
		global $wpdb;
		$wpdb->get_row("update {$wpdb->prefix}users set display_name = 'CARROT TOP' where ID='".$user_id."' ");
		return true;
	}
	add_action( 'admin_init', 'register_carrot_top_update'  );


?>