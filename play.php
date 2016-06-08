<?php
header('Content-Type: text/html; charset=utf-8');
session_start(['cookie_lifetime' => 86400]);

// Exit if no name or key
if (! (isset($_GET['name']) && isset($_GET['key'])))
{
    header("Location: index.php");
}

// Check user's name.
if (preg_match("/^[a-zA-Z0-9]+$/", $_GET['name']))
{
    $name = $_GET['name'];
}
else
{
    header("Location: index.php?error=bad-name");
    exit;
}

// Check users's language.
if (isset($_GET['language']) && ctype_alnum($_GET['language']) && file_exists("lang/" . $_GET['language'] . ".json"))
{
    $language = $_GET['language'];
}
else
{
    $language = "EN";
}

// Check key is valid.
if (strlen($_GET['key']) == 6 && ctype_alnum($_GET['key']))
{
    $keyCode = $_GET['key'];
}
else
{
    header("Location: index.php?error=bad-key");
    exit;
}

$dbPath = "db/" . $keyCode . ".db";

// Check database exists.
if(! file_exists($dbPath))
{
    header("Location: index.php?error=bad-key");
    exit;
}

if ($db = new SQLite3($dbPath, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE))
{
    // Check the match is not going on.
    $stmt = $db->prepare("SELECT Playing FROM Match WHERE Playing=1");
    $res = $stmt->execute();
    $res = $res->fetchArray();
    //TODO: remove after debugging.
    /*if ($res)
    {
        header("Location: index.php?error=match-active");
        exit;
    }*/
    

    // Check if this player is already registered in this match (using session_id).
    $stmt = $db->prepare("SELECT * FROM players WHERE SessID=:sessID");
    $stmt->bindValue(":sessID", session_id());
    $res = $stmt->execute();
    $res = $res->fetchArray();
    
    if ($res)
    {
        // Already registered in this match.
        // Update the values.
        $stmt = $db->prepare("UPDATE players SET Name=:name,Lang=:lang WHERE SessID=:sessID");
        $stmt->bindValue(":name", $name);
        $stmt->bindValue(":lang", $language);
        $stmt->bindValue(":sessID", session_id());
        if (! $stmt->execute())
        {
            header("Location: index.php?error=relogin-database-update");
            exit;
        }
    }
    else
    {
        // New player in this match.
        
        // Register him.
        $stmt = $db->prepare("INSERT INTO players (SessID,Name,First,Host,Role,Lang) VALUES (:sessID,:name,0,0,:role,:lang)");
        $stmt->bindValue(":sessID", session_id());
        $stmt->bindValue(":name", $name);
        $stmt->bindValue(":role", -1);
        $stmt->bindValue(":lang", $language);
        if (! $stmt->execute())
        {
            header("Location: index.php?error=database-register-player");
            exit;
        }
    }
    
    // Get player infos.
    $stmt = $db->prepare("SELECT * FROM players WHERE SessID=:sessID");
    $stmt->bindValue(":sessID", session_id());
    $player = $stmt->execute()->fetchArray();
    if (! $player)
    {
        header("Location: index.php?error=database-get-player-infos");
        exit;
    }
}
else
{
    header("Location: index.php?error=database-unknown-error");
    exit;
}

// Now the page will GET getUpdate.php?key=$key every x seconds.
?>

<!DOCTYPE html>
<head>
    <meta charset="UTF-8">
    <title>Spyfall</title>
    <meta name="author" content="Giorgio Bonvicini">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel='stylesheet' type='text/css' href='https://fonts.googleapis.com/css?family=Marvel:700,400'>
    <link rel='stylesheet' type='text/css' href="main.css">
    
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/2.2.4/jquery.min.js"></script>
</head>
<body>
<script>

// Setup language.
$.ajax({
    url:"lang/<?php echo $language;?>.json",
    async: false
})
.done(function(data){
    // Save localized strings in resource.
    resource = data;
});
</script>
<h1>Spyfall</h1>
<script>shareLink = window.location.href.replace("play.php", "index.php").replace(/&*(name|language)=[a-zA-Z0-9]*&*/g, "");</script>
<p id="share-link">Per invitare altri giocatori condividi questo link:</p>
<p><script>document.write(shareLink);</script></p>
<p id="share-whatsapp"><a href="">Clicca qui</a> per condividerlo via Whatsapp.</p>
<script>$("#share-whatsapp a").attr("href", "whatsapp://send?text=" + shareLink);</script>

<button id="btn-start">Inizio partita</button>

<button id="btn-stop" hidden>Termina partita</button>

<p id="timer"></p>


<h2 id="player-data-header">Datia personaggio</h2>
<div id="toggle-player-data">Mostra/nascondi</div>
<div id="player-data">
</div>

<h2 id="player-list-header">Giocatori</h2>
<ul id="players-list">
</ul>

<h2 id="location-list-header">Location</h2>
<ul id="location-list">
</ul>

<br><br>

<div style="text-align: center">
    <p>Skyfall</p>
    <audio style="width:90%" src="media/Skyfall.mp3" controls></audio>
</div>
<div style="text-align: center">
    <p>Skyfall (instrumental)</p>
    <audio style="width:90%" src="media/Skyfall (instrumental).mp3" controls></audio>
</div>
<div style="text-align: center">
    <p>James Bond theme</p>
    <audio style="width:90%" src="media/James Bond theme.mp3" controls></audio>
</div>

<script>
// Localize the page using the saved strings.
localize();
// Print out the list  of the locations.
resource['locations'].forEach(function(location, index) {
    $("#location-list").append('<li data="on">' + location['name'] + '</li>');
});
$("#location-list li").click(function() {
    if ($(this).attr("data") == "on")
    {
        $(this).attr("data", "off")
    }
    else
    {
        $(this).attr("data", "on")
    }
});

// This will contain the unix timestamp of the end of the match.
endTime = 0;

// Update the page every 10 seconds and the clock every half second.
getUpdate();
setInterval(getUpdate, 10000);
setInterval(setTimer, 500);

$("#btn-start").click(function()
{
    $.get("setUpdate.php" , {key: "<?php echo $keyCode;?>", action: "play", timestamp: Math.floor(Date.now() / 1000)})
    .done(function(data)
    {
        console.log(data);
        getUpdate();
    });
});

$("#btn-stop").click(function()
{
    $.get("setUpdate.php" , {key: "<?php echo $keyCode;?>", action: "stop"})
    .done(function(data)
    {
        console.log(data);
        getUpdate();
    });
});

$("#toggle-player-data").click(function() {
    $("#player-data").toggle("medium");
});

// Update the clock.
function setTimer()
{
    if (endTime == 0)
    {
        $("#timer").empty();
    }
    else if (endTime < Math.floor(Date.now()/1000))
    {
        $("#timer").html("00:00");
    }
    else
    {
        var diff = endTime - Math.floor(Date.now() / 1000)
        var m = Math.floor(diff / 60);
        var s = diff % 60;
        $("#timer").html(("0" + m).slice (-2) + ":" + ("0" + s).slice (-2));
    }
}

// Get an update from the server.
function getUpdate()
{
    $.get("getUpdate.php", {key: "<?php echo $keyCode;?>"})
    .done(function(data) {
        if (data['error'])
        {
            console.log(data['message']);
            return;
        }
        
        if (data['match']['Playing'])
        {
            // Show the collected data (game is on).
            endTime = Math.floor(Date.now()/1000) + data['match']['TimeLeft'];
            $("#toggle-player-data").show();
            $("#player-data").empty();
            $("#player-data").show();
            var name = "<p>" + getResource("name") + ": " + data['player']['Name'] + "</p>";
            var role = "<p>" + getResource("role") + ": " + getRole(data['match']['Location'], data['player']['Role']) + "</p>";
            var location = "<p>" + getResource("location") + ": " + (data['player']['Role'] != 0 ? getLocation(data['match']['Location']) : getResource("unknown")) + "</p>";
            $("#player-data").html(name + role + location);
            if (data['player']['Host'])
            {
                $("#btn-start").hide();
                $("#btn-stop").show();
            }
            else
            {
                $("#btn-start").hide();
                $("#btn-stop").hide();
            }
        }
        else
        {
            // Show the collected data (game is off).
            endTime = 0;
            $("#toggle-player-data").hide();
            $("#player-data").empty();
            $("#player-data").show();
            $("#player-data").html(getResource("not-playing"));
            if (data['player']['Host'])
            {
                $("#btn-start").show();
                $("#btn-stop").hide();
            }
            else
            {
                $("#btn-start").hide();
                $("#btn-stop").hide();
            }              
        }
        
        // Update player list.
        var oldplayersOff = [];
        // Maintain a list of "off" players.
        $("#players-list li").each(function() {
            if ($(this).attr("data") == "off")
            {
                oldplayersOff.push($(this).text());
            }
        });
        $("#players-list").empty();
        // Reapply "off" status to the player in the list.
        data['players'].forEach(function(player, index) {
            var name = player['Name'];
            if (oldplayersOff.indexOf(name) >= 0)
            {
                $("#players-list").append('<li data="off">' + name + (player['First'] == 1 ? ' (' + getResource("first") + ')' : '') +'</li>');
            }
            else
            {
                $("#players-list").append('<li data="on">' + name + (player['First'] == 1 ? ' (' + getResource("first") + ')' : '') + '</li>');
            }
        });
        // When a player name is clicked it's set to strikethrough.
        $("#players-list li").click(function() {
            if ($(this).attr("data") == "on")
            {
                $(this).attr("data", "off")
            }
            else
            {
                $(this).attr("data", "on")
            }
        });
    })
}

function getLocation(index)
{
    return resource["locations"][index]["name"];
}

function getRole(location, index)
{
    return resource["locations"][location]['roles'][index];
}

function localize()
{
    console.log(resource);
    $("#share-link").html(getResource("share-link"));
    $("#share-whatsapp").html(getResource("share-whatsapp"));
    $("#player-data-header").html(getResource("player-data-header"));
    $("#toggle-player-data").html(getResource("toggle-player-data"));
    $("#player-list-header").html(getResource("player-list-header"));
    $("#location-list-header").html(getResource("location-list-header"));
    $("#btn-start").html(getResource("btn-start"));
    $("#btn-stop").html(getResource("btn-stop"));
}

function getResource(res)
{
    // Wait until the resources are available, then return.
    if (typeof resource !== "undefined")
    {
        return resource.text[res];
    }
    else
    {
        setTimeout(getResource(res), 250);
    }
}
</script>

</body>
</html>
